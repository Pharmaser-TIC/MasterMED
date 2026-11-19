<?php
/**
 * Fase 6 — Creación de productos y los 6 archivos de cargue (SIESA y Medicar).
 *
 * Replica el script «SCRIPT CREAR MX - LINAA.R» (nombre, hoja, columnas y orden de cada archivo) con los cambios
 * aprobados por el usuario:
 *   R1  tipo de inventario por TIPO DE MEDICAMENTO (MEDICAMENTOS IN350501 / INSUMOS IN350502), no por el IVA.
 *   R4  precio de la Maestra Tarifaria: línea del contrato del subplán principal (decisión P5-1), su ¿PRECIO CORRECTO- M?.
 *   R5  nunca precio «Validar» (E13 lo impide antes de crear).
 *   R6  Medicar: PRECIO UM VENTA = unitario; PRECIO UN COMPLETA = unitario × presentación.
 *   R9  subplanes del catálogo: una fila por subplán asignado (listas en que está el producto), con el precio de su línea.
 *   R11 lote y fechas por parámetro (LOTE_CODIGO, LOTE_FECHA_CREACION, LOTE_FECHA_VENCIMIENTO).
 *   Moléculas que ya existen en Medicar no van en el archivo 5 (aclaración del usuario sobre A03).
 */
require_once __DIR__ . '/solicitudes.php';
require_once __DIR__ . '/xlsx_writer.php';

/** Nombres y hojas exactos de los 6 archivos. */
const CRE_ARCHIVOS = [
    'creacion'      => ['CREACION_ITEM_INTE PHARMASER_soporteti@pharmaser.com.co.xlsx', 'Versión'],
    'descripciones' => ['DESCRIPCIONES_TECNICAS_ITEM_INTE PHARMASER_soporteti@pharmaser.com.co.xlsx', 'Items'],
    'lotes'         => ['LOTES ITEMS_soporteti@pharmaser.com.co.xlsx', 'Versión'],
    'precios'       => ['ITEM_PRECIOS_soporteti@pharmaser.com.co.xlsx', 'Precios'],
    'moleculas'     => ['1. Formato Creacion Moleculas PHARMASER - MEDICAR.xlsx', 'Moleculas'],
    'articulos'     => ['8. Formato Creacion y Cargue de Articulos MUTUAL SER - PHARMASER - MEDICAR.xlsx', 'Cargue_Articulo'],
];

/**
 * Crea TODOS los productos que cumplen las condiciones (VALIDADOS o CON ALERTA después de revalidar con las bases
 * de este momento; los de ERROR se quedan en la lista). Decisión del usuario (2026-10-11): el usuario no elige.
 * Los registra en «Productos creados» con su f120_id, los saca de la lista por crear y genera los 6 archivos.
 * $ids solo se usa en pruebas internas para limitar a unos productos.
 */
function cre_crear(PDO $pdo, array $ids, int $usuarioId, $consecutivo = null): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $consecutivo = trim((string) $consecutivo);
    if (!preg_match('/^\d{1,7}$/', $consecutivo) || (int) $consecutivo < 1) throw new ErrorApi('Digite el consecutivo (f120_id) por el que va SIESA: solo números.', 422);
    $loteId = sol_abierta($pdo, $usuarioId);
    sol_validar($pdo, $loteId, $usuarioId); // con las bases y catálogos de este momento
    $st = $pdo->prepare("SELECT * FROM proc_lote_item WHERE lote_id = ? AND resultado IN ('VALIDADO', 'CON ALERTA') ORDER BY fila");
    $st->execute([$loteId]);
    $items = $st->fetchAll();
    if ($ids) $items = array_values(array_filter($items, fn($i) => in_array((int) $i['id'], $ids, true)));
    // (2026-11-11) los productos con algo pendiente de aprobación del Administrador (A09) no se crean todavía
    $enEspera = array_fill_keys(array_map('intval', $pdo->query("SELECT DISTINCT lote_item_id FROM proc_resultado WHERE regla_codigo = 'A09'")->fetchAll(PDO::FETCH_COLUMN)), true);
    $esperan = count(array_filter($items, fn($i) => isset($enEspera[(int) $i['id']])));
    $items = array_values(array_filter($items, fn($i) => !isset($enEspera[(int) $i['id']])));
    if (!$items) throw new ErrorApi($esperan ? "Los $esperan productos sin error esperan la aprobación del Administrador (A09): se crean cuando apruebe." : 'No hay productos sin error para crear. Revise el semáforo.', 422);
    $ids = array_map(fn($i) => (int) $i['id'], $items);
    $marcas = implode(',', array_fill(0, count($ids), '?'));

    $productos = cre_armar($pdo, $items);
    $codigos = array_column($productos, 'codigo');
    if (count($codigos) !== count(array_unique($codigos))) throw new ErrorApi('Hay códigos de producto repetidos entre los marcados.', 422);
    // f120_id: el consecutivo por el que va SIESA + 1, + 2… (7 dígitos con ceros a la izquierda, como el cargue)
    foreach ($productos as $k => &$p) $p['f120_id'] = str_pad((string) ((int) $consecutivo + $k + 1), 7, '0', STR_PAD_LEFT);
    unset($p);
    $usados = cre_f120_usados($pdo, array_column($productos, 'f120_id'));
    if ($usados) throw new ErrorApi('Estos f120_id ya existen (' . implode(', ', $usados) . '). Revise el consecutivo por el que va SIESA.', 409);

    // --- registro: nueva solicitud CREADA con los productos, ejecución, productos creados
    $creada = insertar($pdo, 'proc_lote', ['estado' => 'CREADA', 'observacion' => 'Creación ' . date('Y-m-d H:i'), 'creado_por' => $usuarioId,
        'creado_en' => ahora(), 'validado_en' => ahora(), 'cerrado_en' => ahora()]);
    $pdo->prepare("UPDATE proc_lote_item SET lote_id = ?, resultado = 'CREADO', actualizado_en = ? WHERE id IN ($marcas)")->execute([$creada, ahora(), ...$ids]);
    $alertas = count(array_filter($items, fn($i) => $i['resultado'] === 'CON ALERTA'));
    $ejec = insertar($pdo, 'aud_ejecucion', ['lote_id' => $creada, 'usuario_id' => $usuarioId, 'inicio' => ahora(), 'procesados' => count($items),
        'creados' => count($items), 'rechazados' => 0, 'alertas' => $alertas, 'resultado' => 'CREADO']);
    $insProd = $pdo->prepare('INSERT INTO prod_producto (codigo_producto_completo, f120_id, cum, registro_sanitario, descripcion, laboratorio, tipo_medicamento, id_mutual,
                              precio, estado, datos, lote_item_id, ejecucion_id, creado_por, creado_en, actualizado_en) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($productos as $p) {
        try {
            $insProd->execute([$p['codigo'], $p['f120_id'], $p['cum'], $p['registro_sanitario'], $p['notas'], $p['laboratorio'], $p['tipo_medicamento'], $p['id_mutual'],
                $p['precio'], 'PENDIENTE_ID_SIESA', json_encode($p, JSON_UNESCAPED_UNICODE), $p['item_id'], $ejec, $usuarioId, ahora(), ahora()]);
        } catch (PDOException) {
            throw new ErrorApi("El producto {$p['codigo']} o el f120_id {$p['f120_id']} ya está creado.", 409);
        }
        insertar($pdo, 'aud_ejecucion_item', ['ejecucion_id' => $ejec, 'lote_item_id' => $p['item_id'], 'resultado' => 'CREADO', 'motivo' => null]);
        // moléculas enviadas a Medicar en esta creación: la siguiente vez ya no se repiten
        if ($p['molecula_nueva_medicar']) {
            $ex = $pdo->prepare('SELECT 1 FROM prod_molecula_medicar WHERE codigo_molecula = ?');
            $ex->execute([$p['cod_molecula']]);
            if (!$ex->fetchColumn()) insertar($pdo, 'prod_molecula_medicar', ['codigo_molecula' => $p['cod_molecula'], 'fuente' => 'CREACION ' . $ejec, 'creado_en' => ahora()]);
        }
    }
    $archivos = cre_generar_archivos($pdo, $ejec, $productos);
    $pdo->prepare('UPDATE aud_ejecucion SET fin = ?, detalle = ? WHERE id = ?')->execute([ahora(), json_encode(['archivos' => array_column($archivos, 'nombre')], JSON_UNESCAPED_UNICODE), $ejec]);
    auditar($usuarioId, 'PRODUCTOS_CREAR', 'aud_ejecucion', $ejec, null, ['productos' => $codigos, 'consecutivo_siesa' => $consecutivo,
        'f120_id' => array_column($productos, 'f120_id'), 'archivos' => array_column($archivos, 'nombre')]);
    return ['ejecucion' => $ejec, 'creados' => count($productos), 'codigos' => $codigos, 'archivos' => $archivos];
}

/** f120_id de la lista que ya existen en el Maestro SIESA (ítem) o que ya asignó el aplicativo (se compara el número). */
function cre_f120_usados(PDO $pdo, array $f120): array {
    $existentes = cre_f120_existentes($pdo);
    return array_values(array_filter($f120, fn($x) => isset($existentes[(int) $x])));
}

/** Números de ítem del Maestro SIESA vigente y f120_id ya asignados en el aplicativo: [número => SIESA / APP]. */
function cre_f120_existentes(PDO $pdo): array {
    $st = $pdo->prepare('SELECT item FROM ref_siesa_item WHERE carga_id = ? AND item IS NOT NULL ORDER BY id');
    $st->execute([_carga_vigente($pdo, 'MAESTRO_SIESA')]);
    $siesa = array_fill_keys(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), 'SIESA');
    $app = array_fill_keys(array_map('intval', $pdo->query('SELECT f120_id FROM prod_producto WHERE f120_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN)), 'APP');
    return $app + $siesa;
}

/** Para el campo del consecutivo: el ítem más alto del Maestro SIESA vigente y el último f120_id asignado en el aplicativo. */
function cre_consecutivo_sugerido(PDO $pdo): array {
    $siesa = 0; $app = 0;
    foreach (cre_f120_existentes($pdo) as $n => $origen) { if ($origen === 'SIESA') $siesa = max($siesa, $n); else $app = max($app, $n); }
    $fecha = $pdo->query('SELECT creado_en FROM ref_carga WHERE id = ' . _carga_vigente($pdo, 'MAESTRO_SIESA'))->fetchColumn() ?: null;
    return ['maestro_siesa' => $siesa, 'maestro_fecha' => $fecha, 'aplicativo' => $app, 'sugerido' => max($siesa, $app)];
}

/**
 * Datos de cada producto para los archivos (lo que en el Excel eran las columnas calculadas de «CREAR - LISTO»).
 */
function cre_armar(PDO $pdo, array $items): array {
    $molecula = [];
    foreach ($pdo->query('SELECT * FROM cat_molecula') as $m) $molecula[_u($m['cod_gen_audifarma'])] = $m;
    $presentacion = [];
    foreach ($pdo->query('SELECT presentacion_agrupada, codigo, unidad_orden FROM cat_presentacion') as $x) $presentacion[_u($x['presentacion_agrupada'])] = $x;
    $laboratorio = [];
    $labClave = [];
    foreach ($pdo->query('SELECT nombre, codigo, activo FROM cat_laboratorio') as $x) {
        $laboratorio[_u($x['nombre'])] = $x['codigo'];
        if ((int) $x['activo']) $labClave[laboratorio_clave($x['nombre'])] = $x['codigo'];
    }
    $grupo = $pdo->query('SELECT etiqueta_iva, codigo FROM cat_grupo_impositivo')->fetchAll(PDO::FETCH_KEY_PAIR);
    $tipoInv = $pdo->query('SELECT tipo_medicamento, codigo FROM cat_tipo_inventario')->fetchAll(PDO::FETCH_KEY_PAIR);
    $subplan = [];
    foreach ($pdo->query('SELECT id_subplan, nombre FROM cat_subplan') as $x) $subplan[_u($x['nombre'])] = (int) $x['id_subplan'];
    $st = $pdo->prepare('SELECT UPPER(codigo_cup), tipo_servicio FROM ref_capita WHERE carga_id = ? ORDER BY id');
    $st->execute([_carga_vigente($pdo, 'LISTADO_CAPITA')]);
    $capita = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    $st = $pdo->prepare('SELECT DISTINCT codigo_molecula FROM ref_medicar WHERE carga_id = ?');
    $st->execute([_carga_vigente($pdo, 'MAESTRA_MEDICAR')]);
    $enMedicar = array_fill_keys($st->fetchAll(PDO::FETCH_COLUMN), true)
        + array_fill_keys($pdo->query('SELECT codigo_molecula FROM prod_molecula_medicar')->fetchAll(PDO::FETCH_COLUMN), true);

    $out = [];
    foreach ($items as $i) {
        $c = json_decode((string) $i['datos_calculados'], true) ?: [];
        $e = _u($i['codigo_generico_audifarma']);
        $m = $molecula[$e] ?? null;
        $presTxt = _u($i['presentacion_1']) . ' X ' . (int) $i['unidad_presentacion'];
        $p = $presentacion[$presTxt] ?? null;
        $lab = $labClave[laboratorio_clave($i['laboratorio'])] ?? $laboratorio[_u($i['laboratorio'])] ?? null;
        if (!$m || !$p || $lab === null) throw new ErrorApi("Fila {$i['fila']}: faltan datos de catálogo (COD GEN, presentación o laboratorio). Revalide.", 422);
        $codigo = "{$m['codigo_medicar']}_{$p['codigo']}_{$lab}";
        $unidadPres = (int) $i['unidad_presentacion'];
        $grupoImp = $grupo[_u($i['iva'])] ?? null;
        $precio = isset($c['precio_m']) && $c['precio_m'] !== null ? (float) $c['precio_m'] : (isset($c['precio']) ? (float) $c['precio'] : null);
        $subplanes = $c['subplanes'] ?? ['EVENTO POS'];
        $out[] = [
            'item_id' => (int) $i['id'], 'fila' => (int) $i['fila'], 'codigo' => $codigo,
            'cod_auf' => $e, 'cod_molecula' => (string) $m['codigo_medicar'], 'descripcion_generica' => mb_strtoupper((string) $m['descripcion_general']),
            'concentracion_agrupada' => $m['concentracion_agrupada'], 'principio_activo' => $m['principio_activo'], 'forma_farmaceutica' => $m['forma_farmaceutica'],
            'grupo_farmacologico' => $m['grupo_farmacologico'], 'via_administracion' => $m['via_administracion'], 'cadena_frio' => $m['cadena_frio'],
            'cantidad_tope' => (string) $m['cantidad_tope'] !== '' ? $m['cantidad_tope'] : '0', // vacía en COD GEN: el Excel envía 0
            // CATEGORIA PBS de COD GEN (POS / NO POS) en el texto del Excel (PBS / NO PBS)
            'categoria_pbs' => _u($m['pos_no_pos']) === 'NO POS' ? 'NO PBS' : (_u($m['pos_no_pos']) === 'POS' ? 'PBS' : $m['pos_no_pos']),
            'presentacion' => $presTxt, 'presentacion_codigo' => $p['codigo'], 'unidad_orden' => $p['unidad_orden'], 'laboratorio_codigo' => $lab,
            'unidad_presentacion' => $unidadPres,
            // f120_id_unidad_inventario: si la presentación es por 1, la unidad de orden; si no, UND (como el script)
            'unidad_inventario' => $unidadPres === 1 ? $p['unidad_orden'] : 'UND',
            'notas' => trim(implode(' ', array_filter([mb_strtoupper((string) $m['descripcion_general']), $presTxt, $i['nombre_comercial'], $i['laboratorio']]))),
            'grupo_impositivo' => $grupoImp, 'tipo_inventario' => $tipoInv[_u($i['tipo_medicamento'])] ?? null,
            'iva' => $i['iva'], 'cum' => $i['cum'], 'codigo_cum_auxiliar' => $i['codigo_cum_auxiliar'], 'registro_sanitario' => $i['registro_sanitario'],
            'codigo_ium' => $i['codigo_ium'], 'nombre_comercial' => $i['nombre_comercial'], 'laboratorio' => $i['laboratorio'], 'codigo_atc' => $i['codigo_atc'],
            'tipo_medicamento' => $i['tipo_medicamento'], 'fecha_reg_invima' => $i['fecha_reg_invima'],
            'estado_invima' => $i['estado_invima'] === null ? null : mb_strtoupper(mb_substr($i['estado_invima'], 0, 1)) . mb_strtolower(mb_substr($i['estado_invima'], 1)), // «Vigente»
            'presentacion_1' => $i['presentacion_1'], 'forma_farmaceutica_rips' => $i['forma_farmaceutica_rips'], 'concentracion_rips' => $i['concentracion_rips'],
            'unidad_medida_rips' => $i['unidad_medida_rips'],
            'id_mutual' => $c['cod_mutual'] ?? null, 'precio' => $precio, 'subplanes' => $subplanes,
            // precio de cada subplán en Medicar: el ¿PRECIO CORRECTO- M? de su línea de la Maestra Tarifaria (CAPITA / EVENTO SAS / EVENTO)
            'precios_subplan' => array_map(fn($s) => isset($c['lineas'][$s]) ? ($c['lineas'][$s]['precio_m'] ?? $c['lineas'][$s]['precio']) : $precio, $subplanes),
            'subplanes_id' => array_map(fn($s) => $subplan[_u($s)] ?? null, $subplanes),
            // CONTRATO (columna Y del Excel): CAPITA si está en el Listado Cápita (tipo de servicio CAPITACION), si no EVENTO
            'contrato' => str_starts_with(_u($capita[$e] ?? ''), 'CAPITA') ? 'CAPITA' : 'EVENTO',
            'molecula_nueva_medicar' => !isset($enMedicar[(string) $m['codigo_medicar']]),
        ];
    }
    return $out;
}

/** Escribe los 6 archivos de la creación en la carpeta de salidas, los registra (aud_archivo) y arma el ZIP. */
function cre_generar_archivos(PDO $pdo, int $ejecucionId, array $productos): array {
    $dir = rtrim(app_config()['dir_salidas'], '/\\') . '/creacion_' . str_pad((string) $ejecucionId, 5, '0', STR_PAD_LEFT);
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new RuntimeException('No se pudo crear la carpeta de salidas.');
    $hoy = date('Ymd');
    $descMax = (int) parametro('SIESA_DESC_MAX', '40');
    $cortaMax = (int) parametro('SIESA_DESC_CORTA_MAX', '20');
    $datoMax = (int) parametro('SIESA_DATO_TECNICO_MAX', '60');
    $txt = fn($v) => $v === null || $v === '' ? null : (string) $v;
    $num = fn($v) => $v === null || $v === '' ? null : (float) $v;

    $hojas = [];
    // 1. CREACION_ITEM_INTE (20 columnas, orden del script)
    $hojas['creacion'] = [
        ['f120_id', 'f120_referencia', 'f120_descripcion', 'f120_descripcion_corta', 'f120_id_grupo_impositivo', 'f120_id_tipo_inv_serv', 'f120_id_segmento_costo',
         'f120_ind_tipo_item', 'f120_ind_compra', 'f120_ind_venta', 'f120_ind_manufactura', 'f120_ind_lote', 'f120_ind_lote_asignacion', 'f120_vida_util',
         'f120_id_unidad_inventario', 'f120_id_unidad_orden', 'f120_factor_orden', 'f121_fecha_creacion', 'f120_notas', 'f120_ind_exento'],
        array_map(fn($p) => [$p['f120_id'] ?? null, $p['codigo'], mb_substr($p['descripcion_generica'], 0, $descMax), mb_substr((string) $p['principio_activo'], 0, $cortaMax),
            $p['grupo_impositivo'], $p['tipo_inventario'], null, '1', '1', '1', '1', '1', '1', '0', $p['unidad_inventario'], $p['unidad_orden'],
            (string) $p['unidad_presentacion'], $hoy, $p['notas'], '1'], $productos),
    ];
    // 2. DESCRIPCIONES_TECNICAS: producto × campo técnico (catálogo), recortado, sin vacíos
    $campos = $pdo->query('SELECT f104_id FROM cat_campo_tecnico ORDER BY orden, f104_id')->fetchAll(PDO::FETCH_COLUMN);
    $valorCampo = fn(array $p, string $campo) => match (_u($campo)) {
        'REGISTRO SANITARIO' => $p['registro_sanitario'], 'LABORATORIO' => $p['laboratorio'], 'CUM' => $p['cum'], 'PRESENTACION' => $p['presentacion'],
        'CATEGORIA PBS' => $p['categoria_pbs'], 'CADENA DE FRIO' => $p['cadena_frio'], 'ESTADO INVIMA' => $p['estado_invima'],
        'FECHA REG INVIMA' => $p['fecha_reg_invima'], 'CODIGO IUM' => $p['codigo_ium'], 'CODIGO ATC' => $p['codigo_atc'],
        'GRUPO FARMACOLOGICO' => $p['grupo_farmacologico'], 'ID MUTUAL', 'CODIGO GENERICO AUXILIAR' => $p['id_mutual'],
        'NOMBRE COMERCIAL' => $p['nombre_comercial'], 'PRINCIPIO ACTIVO' => $p['principio_activo'], 'FORMA FARMACEUTICA' => $p['forma_farmaceutica'],
        'CONCENTRACION AGRUPADA' => $p['concentracion_agrupada'], 'VIA DE ADMINISTRACION' => $p['via_administracion'], 'PRESENTACION 1' => $p['presentacion_1'],
        'UNIDAD DE PRESENTACION' => (string) $p['unidad_presentacion'], 'CODIGO GENERICO AUDIFARMA' => $p['cod_auf'], 'CODIGO CUM AUXILIAR' => $p['codigo_cum_auxiliar'],
        'CONTRATO' => $p['contrato'], 'CANTIDAD TOPE' => $p['cantidad_tope'], 'TIPO DE MEDICAMENTO' => $p['tipo_medicamento'],
        'FORMA FARMACEUTICA RIPS' => $p['forma_farmaceutica_rips'], 'CONCENTRACION MEDICAMENTO RIPS' => $p['concentracion_rips'],
        'UNIDAD MEDIDA RIPS' => $p['unidad_medida_rips'],
        default => null, // campos que el proceso actual deja vacíos (código de barras, regulado, PMV, contenido…)
    };
    $filasDesc = [];
    foreach ($campos as $campo) {
        foreach ($productos as $p) {
            $v = $valorCampo($p, $campo);
            if ($v === null || trim((string) $v) === '') continue;
            $filasDesc[] = [$p['codigo'], mb_substr((string) $v, 0, $datoMax), $campo];
        }
    }
    $hojas['descripciones'] = [['f120_referencia', 'f123_dato', 'f104_id'], $filasDesc];
    // 3. LOTES (parámetros)
    $hojas['lotes'] = [['Lote', 'Referencia item', 'Fecha creación', 'Fecha vencimiento'],
        array_map(fn($p) => [parametro('LOTE_CODIGO', 'PH001'), $p['codigo'], parametro('LOTE_FECHA_CREACION', '20230101'), parametro('LOTE_FECHA_VENCIMIENTO', '20301201')], $productos)];
    // 4. ITEM_PRECIOS (precio de la Maestra Tarifaria, redondeado)
    $hojas['precios'] = [['Codigo de lista de precios', 'Referencia item', 'Fecha de activación', 'Unidad de medida', 'Precio'],
        array_map(fn($p) => [parametro('SIESA_LISTA_PRECIOS', 'L01'), $p['codigo'], $hoy, $p['unidad_orden'], round((float) $p['precio'])],
            array_filter($productos, fn($p) => $p['precio'] !== null))];
    // 5. Moléculas MEDICAR: solo las que no existen en Medicar (una por molécula)
    $moleculas = [];
    foreach ($productos as $p) {
        if (!$p['molecula_nueva_medicar'] || isset($moleculas[$p['cod_molecula']])) continue;
        $moleculas[$p['cod_molecula']] = [$txt($p['cod_molecula']), 1, $p['cod_auf'], $p['descripcion_generica'], 0, null, 1, 1,
            str_starts_with(_u($p['contrato']), 'EVE') ? 102 : 100, 0, 0, $txt($p['cantidad_tope']), 0, $p['concentracion_agrupada'], $p['forma_farmaceutica'],
            $p['principio_activo'], $p['via_administracion'], 0, null, 1, null, null];
    }
    $hojas['moleculas'] = [['consecutivo', 'convenio(enviarsiempre1)', 'codigoWSsiaplica', 'Descripcion', 'Precio(siemprecero)', 'FechaVigencia(vacia)',
        'cotizantes(siempre1)', 'benefi(siempre1)', 'plan(100capita,102evento)', 'ws_splan_alterna(siempre0)', 'ws_splan_propia(siempre0)', 'Cantidad_tope',
        'especialidad(siempre0)', 'concentracion', 'forma_farma', 'molecula', 'via administracion', 'promocion(siempre cero)', 'codigo_producto (vacia)',
        'id_unidad_medida(siempre 1)', 'concentracion_separada', 'unidades'], array_values($moleculas)];
    // 6. Cargue de Artículos MEDICAR (una fila por subplán)
    $filasArt = [];
    foreach ($productos as $p) {
        foreach ($p['subplanes'] as $k => $sp) {
            $pu = ($p['precios_subplan'][$k] ?? $p['precio']) !== null ? (float) ($p['precios_subplan'][$k] ?? $p['precio']) : null;
            $filasArt[] = [$txt($p['cod_molecula']), $p['descripcion_generica'], $p['concentracion_agrupada'], $p['forma_farmaceutica'], $p['codigo'],
                $p['descripcion_generica'], $p['notas'], $p['presentacion_1'], $p['unidad_presentacion'], 'UND', $p['unidad_presentacion'], $p['unidad_inventario'],
                null, $pu, $pu, $pu === null ? null : ($pu == 1 ? 1 : $pu * $p['unidad_presentacion']), 0, $p['grupo_impositivo'] === '0016' ? 0 : 19, 1,
                'Mutual Ser', 'CONTRIBUTIVO', $sp, $p['subplanes_id'][$k], $p['laboratorio'], null, str_replace('_', '', $p['codigo']), $p['cum'], $p['codigo_atc'],
                $p['registro_sanitario'], null, $p['tipo_medicamento'], null, null, null, $p['cod_auf'], $p['unidad_inventario'], null, $p['cod_auf'], null, null, null, null];
        }
    }
    $hojas['articulos'] = [['COD. MOLECULA', 'MOLECULA', 'CONCENTRACION', 'FORMA F. / VIA DE ADMINISTRACION', 'PLU Cadena', 'Cargue a descripcion MOLECULA en medicar',
        'Cargue a descripcion ARTICULO en medicar', 'Uni. Med Completa', 'contenido', 'UNIDAD MINIMA CONTENIDO', 'Cantidad * Presentacion', 'Uni. Med Venta',
        'Cantidad Blister', 'PRECIO UNI MINIMA', 'PRECIO UM VENTA', 'PRECIO UN COMPLETA', 'COSTO', 'IVA', 'Opcion Cadena', 'CONVENIO', 'PLAN', 'SUB PLAN',
        'ID SUBPLAN', 'LABORATORIO', 'NIT', 'EAN', 'CUM', 'ATC', 'REGISTRO INVIMA', 'CODIGO INSUMO', 'TIPO MEDICAMENTO', 'MARCA', 'PRECIO CONTROLADO',
        'CODIGO MIPRES', 'CODIGO MD', 'UNIDAD MEDIDA', 'CODIGO COMERCIAL', 'CODIGO GENERICO', 'GRUPO MEDICAMENTO', 'CATEGORIA', 'GRUPO INSUMO', 'SUBGRUPO INSUMO'], $filasArt];

    $archivos = [];
    foreach (CRE_ARCHIVOS as $k => [$nombre, $hoja]) {
        [$enc, $filas] = $hojas[$k];
        $ruta = "$dir/$nombre";
        XlsxWriter::guardar($ruta, [['nombre' => $hoja, 'encabezados' => $enc, 'filas' => $filas]]);
        $archivos[] = ['nombre' => $nombre, 'hoja' => $hoja, 'filas' => count($filas)];
        insertar($pdo, 'aud_archivo', ['ejecucion_id' => $ejecucionId, 'nombre' => $nombre, 'hoja' => $hoja, 'filas' => count($filas),
            'hash_sha256' => hash_file('sha256', $ruta), 'ruta' => $ruta, 'creado_en' => ahora()]);
    }
    // ZIP con los 6 archivos
    $zip = new ZipArchive();
    $rutaZip = "$dir.zip";
    if ($zip->open($rutaZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('No se pudo crear el ZIP.');
    foreach (CRE_ARCHIVOS as [$nombre]) $zip->addFile("$dir/$nombre", $nombre);
    $zip->close();
    return $archivos;
}

/** Creaciones realizadas (para descargar de nuevo sus archivos). */
function cre_historial(PDO $pdo): array {
    $lista = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? "GROUP_CONCAT(codigo_producto_completo SEPARATOR ', ')" : "GROUP_CONCAT(codigo_producto_completo, ', ')";
    return $pdo->query("SELECT e.id, e.inicio, e.creados, e.alertas, u.nombre AS usuario,
                               (SELECT $lista FROM prod_producto p WHERE p.ejecucion_id = e.id) AS codigos
                          FROM aud_ejecucion e LEFT JOIN seg_usuario u ON u.id = e.usuario_id WHERE e.resultado = 'CREADO' ORDER BY e.id DESC LIMIT 100")->fetchAll();
}

/** Ruta del ZIP de una creación (solo dentro de la carpeta de salidas). */
function cre_ruta_zip(int $ejecucionId): string {
    $base = realpath(app_config()['dir_salidas']);
    $ruta = $base ? realpath($base . '/creacion_' . str_pad((string) $ejecucionId, 5, '0', STR_PAD_LEFT) . '.zip') : false;
    if (!$ruta || !str_starts_with($ruta, $base)) throw new ErrorApi('Los archivos de esa creación no se encuentran.', 404);
    return $ruta;
}
