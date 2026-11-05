<?php
/**
 * MAESTRA TARIFARIA (fase 4) — se mantiene en el aplicativo (nt_item) y se exporta a Excel para compartirla.
 *
 * - Se guarda SOLO lo que se digita (CUM, códigos, precio de venta, IVA...). Lo que en el Excel eran
 *   fórmulas (contrato CÁPITA/EVENTO, tipo 714/799, validadores, precio + IVA, precio máximo, ¿está en
 *   el contrato?, ¿precio correcto?, CUM ↔ código, precio regulado) lo recalcula el motor contra las
 *   bases vigentes, así nunca queda desactualizado (no hay tablas dinámicas que refrescar).
 * - El contrato y el precio se validan con el COD MUTUAL (decisión del usuario, P1): es el mismo
 *   código con el que se valida la creación.
 * - Corrección frente al Excel (H1): "¿el precio es correcto?" se compara como número (en el Excel el
 *   precio del contrato es texto y la comparación siempre daba "SI").
 */
require_once __DIR__ . '/reglas.php';
require_once __DIR__ . '/datos.php';
require_once __DIR__ . '/lectores_bases.php';
require_once __DIR__ . '/xlsx_writer.php';

/** Columnas digitadas de la Maestra Tarifaria (las que se editan, importan y exportan). */
const NT_CAMPOS = ['cum', 'cum_facturar', 'contrato', 'fecha', 'cod_auf', 'tipo_producto', 'descripcion', 'cod_gen_siesa', 'nuevo_cod_mutual',
                   'cod_mutual', 'precio_venta', 'iva']; // COD MUTUAL FINAL eliminado (decisión del usuario, 2026-10-14)

/**
 * Datos INVIMA / RIPS propios de la Maestra Tarifaria (2026-11-05): campo de la fila => columna de nt_item => largo máximo.
 * Vacío = se toma de SIESA (ATC y RIPS, por el PLU) o de INVIMA (registro y estado, por el CUM). Son del CUM: se guardan en todas sus líneas.
 */
const NT_DATOS_MT = ['atc' => ['atc_mt', 120], 'registro_sanitario' => ['registro_mt', 80], 'estado_registro' => ['estado_registro_mt', 60],
    'ffm_rips' => ['ffm_rips_mt', 40], 'umm_rips' => ['umm_rips_mt', 40], 'upr_rips' => ['upr_rips_mt', 40]];

/** Contratos de una línea de la Maestra Tarifaria. EVENTO y EVENTO SAS se validan con el Contrato Evento (decisión del usuario). */
const NT_CONTRATOS = ['CAPITA', 'EVENTO', 'EVENTO SAS', 'CONSORCIO'];
/**
 * EVENTO SAS no es un contrato (decisión del usuario, 2026-10-14): es la línea del subplán EVENTO SAS SIN AUTORIZACION,
 * que pertenece al contrato EVENTO. Por dentro la línea conserva su tipo (llave CUM FACTURAR + línea, validaciones y
 * precios iguales); en pantalla, filtros y Excel el CONTRATO es CAPITA o EVENTO y el SUBPLAN distingue la línea SAS.
 */
/** Campos de precio de una línea y reglas cuyo texto trae precios (no se muestran sin el permiso PRECIO.VER). */
const NT_CAMPOS_PRECIO = ['precio_venta', 'precio_final', 'precio_contrato', 'precio_maximo', 'precio_regulado', 'tarifa_capita',
    'precio_capita_ok', 'precio_correcto', 'cumple_regulado', 'origen_precio', 'precio1_aprobado_en', 'precio_m_propio', 'precio_aplica', 'precio_digitado', 'fuente_precio'];
const NT_REGLAS_PRECIO = ['NT02', 'NT03', 'NT04', 'NT19', 'NT20'];
/** La línea sin precios (perfil «Consulta sin precios»): campos de precio vacíos y observaciones de precio sin cifras. */
function nt_sin_precios(array $f): array {
    foreach (NT_CAMPOS_PRECIO as $k) if (array_key_exists($k, $f)) $f[$k] = null;
    $f['motivos'] = array_map(fn($m) => in_array($m['codigo'], NT_REGLAS_PRECIO, true) ? ['texto' => 'Observación de precio (reservada)'] + $m : $m, $f['motivos'] ?? []);
    return $f;
}
/** Sin PRECIO.VER no se filtra por campos de precio (se podría deducir el valor). */
function nt_filtros_sin_precios(array $filtros): array { return array_diff_key($filtros, array_flip(NT_CAMPOS_PRECIO)); }
function nt_contrato_vista(?string $linea): ?string { return $linea === 'EVENTO SAS' ? 'EVENTO' : $linea; }
const NT_SUBPLAN_SAS = 'EVENTO SAS SIN AUTORIZACION';

/** Fuentes de precio posibles de un subplán (bases de referencia; especificación §4). */
const SUBPLAN_FUENTES = ['LISTADO_CAPITA', 'CONTRATO_EVENTO', 'LISTADO_EVENTO_SAS', 'LISTADO_CONSORCIO'];

/**
 * Subplán que corresponde a una línea de la Maestra Tarifaria según su contrato (fase 7D). En EVENTO depende de la categoría del
 * COD GEN: NO POS → EVENTO NO PBS; si no, EVENTO POS. La clasificación CAPITA / EVENTO sale del catálogo de subplanes.
 */
function nt_subplan_linea(string $contrato, ?string $posNoPos): ?string {
    return match ($contrato) {
        'CAPITA' => 'CAPITA PBS', 'EVENTO SAS' => 'EVENTO SAS SIN AUTORIZACION', 'CONSORCIO' => 'CONSORCIO',
        'EVENTO' => _u($posNoPos) === 'NO POS' ? 'EVENTO NO PBS' : 'EVENTO POS',
        default => null,
    };
}

/** Normaliza el CONTRATO digitado (CÁPITA -> CAPITA, EVENTO SAS...); null si no es válido. */
function nt_contrato(?string $v): ?string {
    $v = strtr(_u($v), ['Á' => 'A']);
    $v = preg_replace('/\s+/', ' ', $v);
    return in_array($v, NT_CONTRATOS, true) ? $v : null;
}

/** Estados de registro INVIMA que se consideran vigentes (incluye trámite de renovación). */
function _registro_vigente(?string $estado): bool {
    $e = mb_strtoupper((string) $estado);
    return str_contains($e, 'VIGENTE') || str_contains($e, 'TRAMITE') || str_contains($e, 'TRÁMITE');
}

function _carga_vigente(PDO $pdo, string $base): int {
    $st = $pdo->prepare('SELECT id FROM ref_carga WHERE tipo_base = ? AND vigente = 1 ORDER BY id DESC LIMIT 1');
    $st->execute([$base]);
    return (int) $st->fetchColumn();
}

/**
 * Tipo sugerido: 714 (medicamento) si el CUM a facturar es un CUM (con guion) o un código largo; si no,
 * 799 (insumo). Es solo la sugerencia: hay productos digitados a mano como 714 (p.ej. 4039M) y así están
 * en el contrato, por eso el tipo de la fila manda y la diferencia no se marca como error.
 */
function nt_tipo_producto(string $cumFacturar): string {
    return (str_contains($cumFacturar, '-') || mb_strlen($cumFacturar) > 14) ? '714' : '799';
}

function _u(?string $v): string { return mb_strtoupper(trim((string) $v)); }

/**
 * Motor de la Maestra Tarifaria: todas las filas con sus columnas calculadas, estado y motivos.
 * Una consulta por base (por conjunto), no fila por fila. Se calcula una vez por solicitud.
 */
/**
 * Peso entero hacia abajo (decisión del usuario, 2026-10-26): un precio que sale de la tarifa de cápita o del precio regulado nunca se
 * redondea hacia arriba (807,89 → 807; regulado 2.820,80 → 2.820), así el precio facturado no supera el tope. El margen evita que un
 * valor como 806,9999999 (por los decimales del cálculo) baje un peso.
 */
function _nt_peso(float $v): float { return floor($v + 1e-6); }

/** CUM aprobados con varios PLU activos (2026-11-05): [CUM FACTURAR => [PLU aprobados]]. */
function nt_plu_varios_aprobados(PDO $pdo): array {
    $r = [];
    foreach ($pdo->query("SELECT cum_facturar, plus FROM cor_aprobacion WHERE campo = 'PLU_VARIOS' AND activo = 1 ORDER BY id") as $x) {
        $r[_u($x['cum_facturar'])] = array_values(array_filter(array_map('_u', explode(',', (string) $x['plus']))));
    }
    return $r;
}

/** ¿Los PLU activos de un CUM son los que se aprobaron? (si SIESA activa otro PLU, vuelve a ser error) */
function nt_plu_varios_ok(array $aprobados, string $cumFacturar, array $plusActivos): bool {
    $ap = $aprobados[_u($cumFacturar)] ?? null;
    return $ap !== null && count($plusActivos) > 1 && !array_diff(array_map('_u', $plusActivos), $ap);
}

/**
 * Estado de cada PLU en la Maestra Tarifaria (2026-11-05: la Maestra Tarifaria manda y SIESA / MEDICAR son espejo):
 * [id de la línea => [PLU => 1 activo | 0 inactivo]].
 */
function nt_plus_estado(PDO $pdo, ?array &$decididos = null): array {
    $r = []; $decididos = [];
    foreach ($pdo->query('SELECT nt_item_id, plu, activo, actualizado_por FROM nt_item_plu ORDER BY id') as $x) {
        $r[(int) $x['nt_item_id']][_u($x['plu'])] = (int) $x['activo'];
        if ($x['actualizado_por'] !== null) $decididos[(int) $x['nt_item_id']][_u($x['plu'])] = true; // lo definió un usuario
    }
    return $r;
}

/** PLU inactivos en una línea activa (estado por PLU + CONTRATO, 2026-10-26): [id de la línea => [PLU => true]]. */
function nt_plus_inactivos(PDO $pdo): array {
    $r = [];
    foreach ($pdo->query('SELECT nt_item_id, plu FROM nt_item_plu WHERE activo = 0 ORDER BY id') as $x) $r[(int) $x['nt_item_id']][_u($x['plu'])] = true;
    return $r;
}

function nt_calcular(PDO $pdo, bool $refrescar = false): array {
    static $cache = null;
    if ($cache !== null && !$refrescar) return $cache; // $refrescar: después de cambiar la Maestra Tarifaria en la misma solicitud

    $items = $pdo->query('SELECT * FROM nt_item ORDER BY id')->fetchAll();
    $cargas = [];
    foreach (['CONTRATO_EVENTO_22382', 'LISTADO_CAPITA', 'LISTADO_EVENTO_SAS', 'INVIMA', 'MAESTRO_SIESA', 'PRECIOS_REGULADOS', 'MEDICAMENTOS_MUTUAL'] as $b) {
        $cargas[$b] = _carga_vigente($pdo, $b);
    }
    $sub = 'SELECT cum_facturar FROM nt_item';

    // --- bases (solo lo que corresponde a la Maestra Tarifaria)
    $contrato = [];
    $st = $pdo->prepare('SELECT id_mutual, cod_servicio, valor_unitario, estado FROM ref_contrato_evento WHERE carga_id = ? ORDER BY id');
    $st->execute([$cargas['CONTRATO_EVENTO_22382']]);
    // Contrato Evento por ID MUTUAL (= COD MUTUAL de la Maestra Tarifaria); un ID puede estar en 714 y en 799.
    foreach ($st as $r) $contrato[_u($r['id_mutual'])][(string) $r['cod_servicio']] = [(float) $r['valor_unitario'], $r['estado']];

    // Listado Cápita: una fila y una tarifa por Código CUP (= COD AUF de la Maestra Tarifaria).
    $capita = [];
    $st = $pdo->prepare('SELECT codigo_cup, id_mutual, tarifa_pactada FROM ref_capita WHERE carga_id = ? ORDER BY id');
    $st->execute([$cargas['LISTADO_CAPITA']]);
    foreach ($st as $r) {
        $k = _u($r['codigo_cup']);
        // si el COD AUF viene con y sin ID MUTUAL, se conserva la fila que trae el ID (el ID vacío no se valida en NT23)
        if (!isset($capita[$k]) || ($capita[$k]['id'] === '' && _u($r['id_mutual']) !== '')) $capita[$k] = ['id' => _u($r['id_mutual']), 'tarifa' => (float) $r['tarifa_pactada']];
    }

    // Listado EVENTO SAS: el producto se identifica por su CUM (= CUM FACTURAR); las filas sin CUM, por el
    // Cód. Audifarma (= COD AUF). Se guarda el ID MUTUAL del listado para compararlo con el COD MUTUAL.
    $sasCum = []; $sasCod = [];
    $st = $pdo->prepare('SELECT cod_audifarma, cum, id_mutual FROM ref_evento_sas WHERE carga_id = ? ORDER BY id');
    $st->execute([$cargas['LISTADO_EVENTO_SAS']]);
    foreach ($st as $r) {
        $id = _u($r['id_mutual']);
        if ((string) $r['cum'] !== '') $sasCum[_u($r['cum'])][$id] = true;
        else $sasCod[_u($r['cod_audifarma'])][$id] = true;
    }

    // CUM <-> ID MUTUAL: Maestra Mutual Ser (decisión del usuario: una sola base de Mutual, automática)
    $cums = [];
    $atcMutual = []; // ATC por CUM: se usa cuando el ítem de SIESA no lo tiene
    $st = $pdo->prepare("SELECT cum, id_mutual, codigo_atc FROM ref_medicamentos_mutual WHERE carga_id = ? AND cum IN ($sub) ORDER BY id");
    $st->execute([$cargas['MEDICAMENTOS_MUTUAL']]);
    foreach ($st as $r) { $cums[$r['cum']][_u($r['id_mutual'])] = true; if (trim((string) $r['codigo_atc']) !== '') $atcMutual[_u($r['cum'])] ??= trim($r['codigo_atc']); }

    $invima = [];
    $st = $pdo->prepare("SELECT cum, estado_cum, estado_registro, registro_sanitario FROM ref_invima_cum WHERE carga_id = ? AND cum IN ($sub) ORDER BY id");
    $st->execute([$cargas['INVIMA']]);
    foreach ($st as $r) $invima[$r['cum']] = $r;

    $siesa = [];
    $st = $pdo->prepare("SELECT cum, referencia, descripcion, grupo_impositivo, estado, forma_farmaceutica_rips AS ffm, concentracion_rips AS umm, unidad_medida_rips AS upr, codigo_atc AS atc
                         FROM ref_siesa_item WHERE carga_id = ? AND cum IN ($sub) ORDER BY id");
    $st->execute([$cargas['MAESTRO_SIESA']]);
    foreach ($st as $r) {
        $siesa[$r['cum']]['items'][] = $r;
        $h = explode('_', (string) $r['referencia'])[0];
        $siesa[$r['cum']]['h'][$h] = true;
        if (!isset($siesa[$r['cum']]['grupo']) || _u($r['estado']) === 'ACTIVO') $siesa[$r['cum']]['grupo'] = $r['grupo_impositivo'];
        if (_u($r['estado']) === 'ACTIVO') $siesa[$r['cum']]['activos'][] = $r['referencia']; // Inactivo / Bloqueado no cuentan
    }
    // Insumos y nutricionales (CUM FACTURAR sin formato de CUM): en SIESA no llevan CUM; el código de la Maestra Tarifaria es el
    // MED-CODIGO GENERICO AUDIFARMA del ítem.
    $siesaGen = [];
    $st = $pdo->prepare("SELECT cod_gen_audifarma, referencia, descripcion, estado, forma_farmaceutica_rips AS ffm, concentracion_rips AS umm, unidad_medida_rips AS upr, codigo_atc AS atc
                         FROM ref_siesa_item WHERE carga_id = ? AND cod_gen_audifarma IN ($sub) ORDER BY id");
    $st->execute([$cargas['MAESTRO_SIESA']]);
    foreach ($st as $r) $siesaGen[_u($r['cod_gen_audifarma'])][] = $r;
    // MEDICAR por CUM: Opcion Cadena 1 = activo (9 = inactivo)
    $medicar = [];
    $st = $pdo->prepare("SELECT cum, plu_cadena, opcion FROM ref_medicar WHERE carga_id = ? AND cum IN ($sub) ORDER BY id");
    $st->execute([_carga_vigente($pdo, 'MAESTRA_MEDICAR')]);
    foreach ($st as $r) if (trim((string) $r['opcion']) === '1') $medicar[$r['cum']][$r['plu_cadena']] = true;
    // Fase 7 (NT maestra principal): ¿la línea está creada en MEDICAR en el subplán de su contrato? Por PLU (REFERENCIA
    // SIESA = PLU Cadena); sin PLU en SIESA, por CUM. Opción Cadena 1 = activo, 9 = inactivo.
    $cargaMedicar = _carga_vigente($pdo, 'MAESTRA_MEDICAR');
    $medPlu = []; $medCum = [];
    $st = $pdo->prepare('SELECT plu_cadena, cum, subplan, opcion FROM ref_medicar WHERE carga_id = ? ORDER BY id');
    $st->execute([$cargaMedicar]);
    foreach ($st as $r) {
        $sp = _u($r['subplan']); $activo = trim((string) $r['opcion']) === '1';
        $plu = _u($r['plu_cadena']);
        $medPlu[$plu][$sp] = ($medPlu[$plu][$sp] ?? false) || $activo;
        if ((string) $r['cum'] !== '') $medCum[_u($r['cum'])][$sp][$plu] = ($medCum[_u($r['cum'])][$sp][$plu] ?? false) || $activo;
    }

    $regulado = [];
    $st = $pdo->prepare('SELECT cod_gen, precio_regulado FROM ref_precio_regulado WHERE carga_id = ? ORDER BY id');
    $st->execute([$cargas['PRECIOS_REGULADOS']]);
    foreach ($st as $r) $regulado[_u($r['cod_gen'])] = (float) $r['precio_regulado'];

    $codGen = array_fill_keys(array_map('_u', $pdo->query('SELECT cod_gen_audifarma FROM cat_molecula')->fetchAll(PDO::FETCH_COLUMN)), true);
    $posNoPos = [];
    $molCat = []; // COD GEN (moléculas) por COD AUF: descripción y COD GEN SIESA (= Cód. Medicar), que no se editan en la Maestra Tarifaria
    foreach ($pdo->query('SELECT cod_gen_audifarma, pos_no_pos, descripcion_general, codigo_medicar, clasificacion_entrega FROM cat_molecula') as $r) {
        $posNoPos[_u($r['cod_gen_audifarma'])] = $r['pos_no_pos'];
        $molCat[_u($r['cod_gen_audifarma'])] = [mb_strtoupper(trim((string) $r['descripcion_general'])) ?: null, trim((string) $r['codigo_medicar']) ?: null,
            trim((string) $r['clasificacion_entrega']) ?: null];
    }
    // Catálogo de subplanes: clasificación CAPITA / EVENTO (fase 7D)
    $subplanCat = [];
    foreach ($pdo->query('SELECT nombre, clasificacion, fuente, activo FROM cat_subplan') as $r) $subplanCat[_u($r['nombre'])] = $r;
    $ivaGrupo = [];
    foreach ($pdo->query('SELECT codigo, iva_medicar FROM cat_grupo_impositivo') as $g) $ivaGrupo[$g['codigo']] = ((float) $g['iva_medicar']) / 100;

    // --- primera pasada: agregados por código (solo filas activas), como las tablas dinámicas del Excel
    // $enJ = COD MUTUAL usados en la Maestra Tarifaria (para «CUM no enlazado con ningún código»)
    $maxPorJ = []; $preciosPorJ = []; $enJ = []; $lineaMax = [];
    foreach ($items as &$it) {
        $d = (string) $it['cum_facturar'];
        // COD GEN SIESA: el digitado (como las tablas dinámicas del Excel); si falta, el del Maestro SIESA.
        $it['_h'] = (string) $it['cod_gen_siesa'] !== '' ? (string) $it['cod_gen_siesa'] : (string) (array_key_first($siesa[$d]['h'] ?? []) ?? '');
        // 7F (D-E1, D-E6): en CAPITA el precio sale del Listado Cápita (tarifa pactada, ya trae el IVA) y nunca supera el precio
        // regulado del COD AUF; el precio de venta (sin IVA) se calcula y deja de digitarse. En EVENTO y EVENTO SAS se conserva el
        // PRECIO DE VENTA propio de la línea y el Contrato Evento es solo el tope: si el precio M lo supera, GLOSA TARIFA (NT02), como
        // en el Excel (decisión del usuario, 2026-10-26: p.ej. M03100 se queda en 111.600 aunque el contrato pague 117.180).
        $fuente = $it['contrato'] === 'CAPITA' ? ($capita[_u($it['cod_auf'])]['tarifa'] ?? null) : null;
        $it['fuente_precio'] = $fuente === null ? 'DIGITADO' : 'LISTADO CAPITA';
        $regIt = $regulado[_u($it['cod_auf'])] ?? null;
        if ($fuente !== null && $regIt !== null && _nt_peso($fuente) > _nt_peso($regIt)) { $fuente = $regIt; $it['fuente_precio'] = 'PRECIO REGULADO'; }
        if ($fuente !== null) $fuente = _nt_peso((float) $fuente); // peso entero hacia abajo
        $it['precio_digitado'] = $it['precio_venta'];
        $it['precio_aplica'] = $fuente;
        if ($fuente !== null) $it['precio_venta'] = round($fuente / (1 + (float) $it['iva']), 2);
        $it['_p'] = $fuente !== null ? $fuente : ($it['precio_venta'] === null ? null : round((float) $it['precio_venta'] * (1 + (float) $it['iva'])));
        if (!(int) $it['activo']) continue;
        $j = _u($it['cod_mutual']);
        // ¿PRECIO CORRECTO- M?: el mayor precio final dentro del mismo contrato, agrupado por COD MUTUAL en EVENTO /
        // EVENTO SAS (el contrato paga por ID MUTUAL) y por COD AUF en CAPITA (decisión del usuario, 2026-09-29: la
        // cápita se pacta por COD AUF; el precio de venta de ese COD AUF debe ser igual a su tarifa).
        $jc = $it['contrato'] . '|' . ($it['contrato'] === 'CAPITA' ? _u($it['cod_auf']) : $j);
        if ($it['_p'] !== null) {
            // línea que fija el precio M (para explicar NT19 / NT03 cuando otra línea del mismo grupo lo sube)
            if ($it['_p'] > ($maxPorJ[$jc] ?? -1)) $lineaMax[$jc] = ['cum' => $d, 'cod_auf' => _u($it['cod_auf'])];
            $maxPorJ[$jc] = max($maxPorJ[$jc] ?? 0, $it['_p']); $preciosPorJ[$jc][$d] = $it['_p'];
        }
        if ($j !== '') $enJ[$j] = true;
    }
    unset($it);

    $inactivasNt = reglas_inactivas($pdo);                // una regla inactiva no se aplica
    $estadoPlu = nt_plus_estado($pdo, $decididosPlu);      // estado de cada PLU en la Maestra Tarifaria (PLU + CONTRATO)
    $pendPlu = [];                                         // PLU que entran a la Maestra Tarifaria o cambian solos (se guardan al final)
    $variosAprobados = nt_plu_varios_aprobados($pdo);       // CUM aprobados con varios PLU activos
    $personalizadasNt = reglas_personalizadas($pdo, 'NT');  // reglas creadas por el Administrador
    // --- segunda pasada: columnas calculadas y reglas por fila
    $filas = [];
    foreach ($items as $it) {
        $d = (string) $it['cum_facturar']; $e = _u($it['cod_auf']); $j = _u($it['cod_mutual']);
        $esCum = (bool) preg_match('/^\d+-\d+$/', $d);
        $itemsSiesa = $siesa[$d]['items'] ?? ($esCum ? [] : ($siesaGen[_u($d)] ?? []));
        // La Maestra Tarifaria manda (2026-11-05): cada PLU de la línea tiene su estado en la Maestra Tarifaria (nt_item_plu); SIESA y
        // MEDICAR deben ser espejo y lo distinto sale en Inconsistencias. El PLU de SIESA que aún no está en la Maestra Tarifaria entra:
        //  - si la línea ya tiene un PLU activo: INACTIVO y «por definir» (decisión del usuario): sale NT29 hasta que el usuario lo apruebe
        //    («Aprobar varios PLU») o lo deje inactivo; insumos (799) y VITAL NO DISPONIBLE llevan varias presentaciones: entra como en SIESA;
        //  - si no tiene ninguno (arranque o producto nuevo): como está hoy en SIESA.
        // Una línea activa sin ningún PLU activo (se reactivó la línea) toma los activos de SIESA o, si no hay, el principal.
        $idL = (int) $it['id'];
        $est = $estadoPlu[$idL] ?? [];
        $exentoPlu = (in_array((string) $it['tipo_producto'], ['714', '799'], true) ? (string) $it['tipo_producto'] : nt_tipo_producto($d)) === '799'
            || _u($it['cum']) === 'VITAL NO DISPONIBLE';
        $deLinea = _nt_plus_de_linea($itemsSiesa, (string) ($it['_h'] ?? ''), $est);
        $presentes = array_intersect_key($est, $deLinea);
        $cambio = [];
        foreach (array_diff_key($deLinea, $est) as $pl => $r) $cambio[$pl] = in_array(1, $presentes, true) && !$exentoPlu ? 0 : (_u($r['estado']) === 'ACTIVO' ? 1 : 0);
        $tras = $cambio + $presentes;
        if ($tras && !in_array(1, $tras, true) && ((int) $it['activo'] || !$presentes)) {
            $act = array_keys(array_filter($deLinea, fn($r) => _u($r['estado']) === 'ACTIVO'));
            foreach ($act ?: [_nt_plu_principal(array_keys($deLinea), [$e, _u((string) ($it['_h'] ?? ''))])] as $pl) $cambio[$pl] = 1;
        }
        foreach ($cambio as $pl => $v) { $pendPlu[] = [$idL, (string) $pl, $v, isset($est[$pl])]; $est[$pl] = $v; }
        $estadoPlu[$idL] = $est;
        $siesaLinea = _nt_siesa_item($deLinea, $est);
        // PLU por definir: activo en SIESA, inactivo en la Maestra Tarifaria sin que un usuario lo haya decidido, en una línea con PLU activo
        $siesaLinea['_plus_pend'] = $exentoPlu || !in_array(1, $siesaLinea['_plus_mt'], true) ? [] : array_map('strval', array_keys(array_filter($deLinea,
            fn($r, $pl) => ($est[$pl] ?? 0) === 0 && _u($r['estado']) === 'ACTIVO' && !isset($decididosPlu[$idL][$pl]), ARRAY_FILTER_USE_BOTH)));
        // ATC: el del ítem de SIESA; si no lo tiene (o dice NO APLICA), el de la Maestra Mutual por el CUM (antes de las reglas, que lo validan)
        $atc = fn(?string $v) => ($v === null || in_array(_u($v), ['NO APLICA', 'NA', 'N/A', '0'], true)) ? ($atcMutual[_u($d)] ?? $v) : $v;
        $siesaLinea['atc'] = $atc($siesaLinea['atc']);
        foreach ($siesaLinea['_por_plu'] as &$x) $x['atc'] = $atc($x['atc']);
        unset($x);
        $k = (string) $it['contrato']; $esCapita = $k === 'CAPITA';
        $subplanLinea = nt_subplan_linea($k, $posNoPos[_u($it['cod_auf'])] ?? null);
        // MEDICAR en el subplán de la línea: EVENTO va en EVENTO POS o EVENTO NO PBS según el POS / NO POS del COD GEN (2026-10-19)
        $subplanesMed = $subplanLinea !== null ? [$subplanLinea] : (NT_SUBPLAN_MEDICAR[$k] ?? []);
        // la línea se valida con sus PLU activos en la Maestra Tarifaria (el PLU inactivo no genera errores)
        $medLinea = _nt_medicar($siesaLinea['_plus'], $d, $subplanesMed, $medPlu, $medCum);
        $f = in_array((string) $it['tipo_producto'], ['714', '799'], true) ? (string) $it['tipo_producto'] : nt_tipo_producto($d);
        $gm = $esCapita ? $e : $j; // código con el que se agrupa el precio M
        $grupoM = $esCapita ? "COD AUF $e" : "COD MUTUAL $j";
        $p = $it['_p']; $v = $maxPorJ["$k|$gm"] ?? $p;
        // Si el precio M del grupo supera el precio regulado del COD AUF de la línea, la línea conserva su propio
        // precio (PRECIO DE VENTA 2026 + IVA), como en el Excel original (decisión del usuario, 2026-09-29).
        $regLinea = $regulado[$e] ?? null;
        $mPorRegulado = $regLinea !== null && $v !== null && $p !== null && $v > _nt_peso($regLinea) && $p < $v;
        if ($mPorRegulado) $v = $p;
        $m = [];
        $motivo = function (string $codigo, string $nivel, string $texto) use (&$m) { $m[] = ['codigo' => $codigo, 'nivel' => $nivel, 'texto' => $texto]; };

        // PRECIO CONTRATO: se cruza ID MUTUAL = COD MUTUAL y el tipo de servicio de la Maestra Tarifaria (714 / 799). Un ID puede
        // estar en los dos servicios del contrato (p.ej. 6953): se toma el precio del tipo de la Maestra Tarifaria y se valida.
        // Solo si el ID está en el contrato con OTRO tipo, no se escoge precio: alerta NT18 para corregir la Maestra Tarifaria.
        // Solo las líneas EVENTO y EVENTO SAS se validan con el contrato; la línea CAPITA, con el Listado Cápita.
        $ctrId = !$esCapita && $j !== '' ? ($contrato[$j] ?? null) : null;
        $problemaTipo = $ctrId && !isset($ctrId[$f])
            ? "El COD MUTUAL $j está en el contrato como " . implode(' y ', array_keys($ctrId)) . " y en la Maestra Tarifaria es $f: corregir el tipo de producto en la Maestra Tarifaria"
            : null;
        $ctr = $ctrId && $problemaTipo === null ? $ctrId[$f] : null;
        $enContrato = $esCapita ? 'NO APLICA' : ($ctrId ? 'SI' : 'NO');
        $precioContrato = $ctr[0] ?? null;
        $precioCorrecto = $esCapita ? 'NO APLICA' : (!$ctrId ? 'NO ESTA EN EL CONTRATO' : ($problemaTipo !== null ? 'REVISAR TIPO DE SERVICIO'
            : ($v !== null && $v <= $precioContrato + 0.5 ? 'SI' : 'GLOSA TARIFA')));
        $reg = $regulado[$e] ?? null;

        // CUM ↔ código (misma lógica de la columna T del Excel)
        $codigosCum = $cums[$d] ?? [];
        $n = count(array_intersect_key($codigosCum, $enJ));
        $okPar = isset($codigosCum[$j]);
        if (!$ctrId && !$esCapita) $cumCodigo = 'NO ESTA EN EL CONTRATO';
        elseif ($f === '799' && _u($d) === $j) $cumCodigo = '(OK) ES INSUMO';
        elseif (_u($d) === $j) $cumCodigo = '(OK) CODIGO CONTRATO';
        elseif ($n === 0) $cumCodigo = '(AGREGAR)CUM NO ENLAZADO CON NINGUN COD';
        elseif ($okPar) $cumCodigo = $n === 1 ? '(OKOK) CUM ENLAZADO CORRECTAMENTE' : '(OK) CUM ENLAZADO CORRECTAMENTE PERO EN VARIOS COD';
        else $cumCodigo = '(ERROR)CUM NO ENLAZADO CON ESE COD';

        // Precio regulado: el ¿PRECIO CORRECTO- M? (precio máximo del COD MUTUAL) no puede superarlo.
        $topeReg = $reg !== null ? _nt_peso($reg) : null; // el precio entero máximo que no supera el regulado
        $cumpleRegulado = $topeReg === null ? 'NO REGULADO' : ($v === null || $v <= $topeReg ? 'SI' : 'NO');
        // Cápita: el ¿PRECIO CORRECTO- M? debe ser igual a la tarifa pactada del Listado Cápita (una diferencia solo
        // de decimales, p.ej. 807 frente a 807,89, es correcta: decisión del usuario), y la tarifa no puede superar el regulado.
        $cap = $esCapita ? ($capita[$e] ?? null) : null;
        $tarifaCapita = $cap['tarifa'] ?? null;
        // Si la tarifa supera el precio regulado, lo correcto es facturar el regulado: el precio facturado nunca puede
        // superar el regulado (decisión del usuario, 2026-09-29), así que precio M = regulado también es correcto.
        $tarifaSobreRegulado = $cap !== null && $topeReg !== null && _nt_peso($tarifaCapita) > $topeReg;
        $precioCapitaOk = $cap === null ? 'NO APLICA' : ($v !== null && abs($v - $tarifaCapita) < 1 ? 'SI'
            : ($tarifaSobreRegulado && $v !== null && abs($v - $topeReg) < 1 ? 'SI: PRECIO REGULADO' : 'NO'));
        // Columnas unificadas (decisión del usuario): «¿esta en el contrato?», «¿el precio es correcto?» y «PRECIO
        // CONTRATO» se validan con el contrato de la línea: Contrato Evento (EVENTO / EVENTO SAS) o Listado Cápita.
        if ($esCapita) {
            $enContrato = $cap !== null ? 'SI' : 'NO';
            $precioContrato = $tarifaCapita;
            $precioCorrecto = $cap === null ? 'NO ESTA EN EL LISTADO CAPITA' : ($precioCapitaOk === 'NO' ? 'NO: DIFERENTE A LA TARIFA' : $precioCapitaOk);
        }
        // EVENTO SAS: el producto está en el listado y su ID MUTUAL debe ser el COD MUTUAL de la Maestra Tarifaria.
        $idsSas = $k === 'EVENTO SAS' ? ($sasCum[_u($d)] ?? $sasCod[$e] ?? null) : null;
        // (strval: PHP convierte en entero una llave numérica como 926047)
        $idsSasValidos = $idsSas === null ? [] : array_values(array_diff(array_map('strval', array_keys($idsSas)), ['']));
        $problemaSas = $idsSasValidos && !in_array($j, $idsSasValidos, true)
            ? 'En el Listado EVENTO SAS el ID MUTUAL es ' . implode(', ', $idsSasValidos) . " y en la Maestra Tarifaria el COD MUTUAL es " . ($j !== '' ? $j : '(vacío)') . ': corregir el código en la Maestra Tarifaria o en el listado'
            : null;

        if ((int) $it['activo']) {
            if ($j === '') $motivo('NT06', 'ERROR', 'Sin COD MUTUAL');
            elseif (!$ctrId && !$esCapita) $motivo('NT01', 'ERROR', "El COD MUTUAL $j no está en el contrato");
            if ($esCapita && !isset($capita[$e])) $motivo('NT22', 'ERROR', "La línea es CAPITA pero el COD AUF $e no está en el Listado Cápita");
            if ($k === 'EVENTO SAS' && $idsSas === null) $motivo('NT22', 'ERROR', "La línea es del subplán EVENTO SAS SIN AUTORIZACION pero el producto no está en el Listado EVENTO SAS");
            if ($problemaTipo !== null) $motivo('NT18', 'ALERTA', $problemaTipo);
            if ($precioCorrecto === 'GLOSA TARIFA') {
                // Cómo solucionarlo: cada fila del COD MUTUAL con precio final por encima del contrato debe bajar.
                $altas = array_keys(array_filter($preciosPorJ["$k|$j"] ?? [], fn($x) => $x > $precioContrato + 0.5));
                $maxVenta = floor($precioContrato / (1 + (float) $it['iva']));
                $motivo('NT02', 'ERROR', sprintf('Glosa de tarifa: el precio M %s supera el del contrato %s. Para no generar glosa, el precio final debe ser máximo %s (precio de venta máximo %s con IVA %s%%) en: %s',
                    number_format($v, 0, ',', '.'), number_format($precioContrato, 0, ',', '.'), number_format($precioContrato, 0, ',', '.'),
                    number_format($maxVenta, 0, ',', '.'), (float) $it['iva'] * 100, implode(', ', $altas)));
            }
            if ($ctr && $ctr[1] !== 'A') $motivo('NT16', 'ALERTA', 'El producto está inactivo en el contrato');
            if ($precioCapitaOk === 'NO') {
                $fmt = fn($x) => number_format((float) $x, fmod((float) $x, 1) ? 2 : 0, ',', '.');
                $lm = $lineaMax["$k|$gm"] ?? null;
                if ($p !== null && abs($p - $tarifaCapita) < 1 && $lm && $lm['cum'] !== $d) {
                    // El precio de esta línea está bien: otra línea CAPITA del mismo COD AUF tiene un precio mayor.
                    $motivo('NT19', 'ERROR', sprintf('Precio de cápita: el precio final de esta línea (%s) es igual a la tarifa, pero el precio M del COD AUF %s es %s por la línea %s: iguale el PRECIO DE VENTA de esa línea a la tarifa %s',
                        $fmt($p), $e, $fmt($v), $lm['cum'], $fmt($tarifaCapita)));
                } else {
                    $motivo('NT19', 'ERROR', sprintf('Precio de cápita: el precio M %s (mayor precio final de las líneas CAPITA del COD AUF %s) debe ser igual a la tarifa pactada %s del Listado Cápita: ajuste el PRECIO DE VENTA',
                        $fmt($v), $e, $fmt($tarifaCapita)));
                }
            }
            // El ID MUTUAL del Listado Cápita (por el COD AUF) debe ser el COD MUTUAL de la línea.
            if ($cap !== null && $cap['id'] !== '' && $cap['id'] !== $j) $motivo('NT23', 'ALERTA',
                "En el Listado Cápita el COD AUF $e tiene ID MUTUAL {$cap['id']} y en la Maestra Tarifaria el COD MUTUAL es " . ($j !== '' ? $j : '(vacío)') . ': corregir el código en la Maestra Tarifaria o en el listado');
            // NT20 solo si el precio M no quedó correcto: si es igual a la tarifa o al regulado, no es error (decisión del usuario, 2026-10-03)
            if ($cap !== null && $topeReg !== null && _nt_peso($tarifaCapita) > $topeReg && $precioCapitaOk === 'NO') $motivo('NT20', 'ERROR', sprintf('La tarifa de cápita %s supera el precio regulado %s',
                number_format($tarifaCapita, 0, ',', '.'), number_format($topeReg, 0, ',', '.')));
            if ($problemaSas !== null) $motivo('NT21', 'ALERTA', $problemaSas);
            if ($cumpleRegulado === 'NO') {
                $lmr = $lineaMax["$k|$gm"] ?? null;
                $otra = $p !== null && $p <= $topeReg && $lmr && $lmr['cum'] !== $d
                    ? sprintf('. El precio final de esta línea (%s) no lo supera: el precio M lo fija la línea %s (COD AUF %s) del mismo %s; revise el precio de esa línea',
                        number_format($p, 0, ',', '.'), $lmr['cum'], $lmr['cod_auf'], $grupoM)
                    : '. Baje el precio de venta de esta línea';
                $motivo('NT03', 'ERROR', sprintf('El precio correcto M %s supera el precio regulado %s del COD AUF %s', number_format($v, 0, ',', '.'), number_format($topeReg, 0, ',', '.'), $e) . $otra);
            }
            // Precio 1: error, salvo que el Administrador haya aprobado la excepción para esa línea (4.10).
            if ($it['precio_venta'] === null) $motivo('NT04', 'ERROR', 'Sin precio de venta');
            elseif ((float) $it['precio_venta'] <= 1 && !$it['precio1_aprobado_en']) $motivo('NT04', 'ERROR', 'Precio igual a 1: pendiente de aprobación del Administrador');
            if (str_starts_with($cumCodigo, '(ERROR)')) $motivo('NT05', 'ERROR', "El CUM $d no está enlazado con $j en la Maestra Mutual Ser"
                . ($codigosCum ? ' (allí está con ' . implode(', ', array_keys($codigosCum)) . ')' : ''));
            if (str_starts_with($cumCodigo, '(AGREGAR)')) $motivo('NT07', 'ALERTA', "El CUM $d no está enlazado con ningún código de la Maestra Tarifaria");
            if (preg_match('/^\d+-\d+$/', $d)) {
                $inv = $invima[$d] ?? null;
                // Decisión del usuario (2026-09-29):
                //  - CUM inactivo con registro vigente: no es error.
                //  - CUM inactivo con registro vencido: error solo si sigue activo en SIESA (Estado Activo) o en
                //    MEDICAR (Opcion Cadena 1); si está inactivo en los dos, está bien.
                //  - CUM no encontrado en INVIMA: alerta hasta que el Administrador lo valide a mano (NT10).
                //  - CUM activo con registro no vigente: error si sigue activo en SIESA o MEDICAR, porque no se puede
                //    dispensar ni comprar (NT11).
                $activoEn = array_filter([
                    !empty($siesa[$d]['activos']) ? 'SIESA (' . implode(', ', array_unique($siesa[$d]['activos'])) . ')' : null,
                    !empty($medicar[$d]) ? 'MEDICAR (' . implode(', ', array_keys($medicar[$d])) . ')' : null,
                ]);
                if (!$inv) { if (!$it['cum_validado_en']) $motivo('NT10', 'ALERTA', 'CUM no encontrado en INVIMA: validarlo a mano'); }
                else {
                    $cumActivo = _u($inv['estado_cum']) === 'ACTIVO';
                    $regVigente = _registro_vigente($inv['estado_registro']);
                    if ($cumActivo && !$regVigente && $activoEn) $motivo('NT11', 'ERROR', "CUM activo con registro {$inv['registro_sanitario']} {$inv['estado_registro']}: no se puede dispensar ni comprar y sigue activo en "
                        . implode(' y ', $activoEn) . ': inactivarlo');
                    if (!$cumActivo && !$regVigente) {
                        $donde = $activoEn;
                        if ($donde) $motivo('NT24', 'ERROR', "CUM {$inv['estado_cum']} en INVIMA y registro {$inv['registro_sanitario']} {$inv['estado_registro']}, pero sigue activo en "
                            . implode(' y ', $donde) . ': inactivarlo');
                    }
                }
            }
            // registro / estado editados en la Maestra Tarifaria: alerta solo si el CUM está en INVIMA y no coinciden (NT11 / NT24 siguen
            // con INVIMA). Si el CUM no está en INVIMA no es alerta: el usuario está completando el dato (pedido del usuario, 2026-11-05)
            $invR = $invima[$d] ?? null;
            if ($invR && (($it['registro_mt'] ?? null) !== null || ($it['estado_registro_mt'] ?? null) !== null)) {
                $reg = fn($v) => preg_replace('/[^A-Z0-9]/', '', preg_replace('/^\s*INVIMA\s*/', '', _u($v)));
                $dif = [];
                if (($it['registro_mt'] ?? null) !== null && $reg($it['registro_mt']) !== $reg($invR['registro_sanitario']))
                    $dif[] = "registro {$it['registro_mt']} (INVIMA: {$invR['registro_sanitario']})";
                if (($it['estado_registro_mt'] ?? null) !== null && _u($it['estado_registro_mt']) !== _u($invR['estado_registro']))
                    $dif[] = "estado {$it['estado_registro_mt']} (INVIMA: {$invR['estado_registro']})";
                if ($dif) $motivo('NT31', 'ALERTA', 'Dato editado en la Maestra Tarifaria distinto de INVIMA: ' . implode('; ', $dif));
            }
            $grupo = $siesa[$d]['grupo'] ?? null;
            if ($grupo !== null && isset($ivaGrupo[$grupo]) && abs($ivaGrupo[$grupo] - (float) $it['iva']) > 0.001) {
                $motivo('NT12', 'ALERTA', sprintf('IVA %s%% y SIESA tiene grupo %s (%s%%)', (float) $it['iva'] * 100, $grupo, $ivaGrupo[$grupo] * 100));
            }
            if ($e !== '' && !isset($codGen[$e])) $motivo('NT13', 'ALERTA', "COD AUF $e no existe en COD GEN");
            if (isset($siesa[$d]) && (string) $it['cod_gen_siesa'] !== '' && !isset($siesa[$d]['h'][(string) $it['cod_gen_siesa']])) {
                $motivo('NT14', 'ALERTA', "COD GEN SIESA {$it['cod_gen_siesa']}; en el Maestro SIESA es " . implode(', ', array_keys($siesa[$d]['h'])));
            }
            // NT28: el subplán de la línea debe estar parametrizado (activo, con clasificación CAPITA / EVENTO)
            $spc = $subplanLinea !== null ? ($subplanCat[$subplanLinea] ?? null) : null;
            if (!$spc || !(int) $spc['activo'] || !in_array($spc['clasificacion'], ['CAPITA', 'EVENTO'], true)) {
                $motivo('NT28', 'ALERTA', $subplanLinea === null ? "El contrato «{$k}» no tiene subplán definido" : "El subplán $subplanLinea no está parametrizado"
                    . ($spc ? (!(int) $spc['activo'] ? ' (inactivo)' : ' (sin clasificación CAPITA / EVENTO)') : ' (no existe en el catálogo)') . ': parametrizarlo en Catálogos → Subplanes Medicar');
            }
            // NT29: la línea debe tener un solo PLU activo EN LA MAESTRA TARIFARIA (la MT manda, 2026-11-05); no aplica a insumos (799) ni a
            // VITAL NO DISPONIBLE (aclaración del usuario, 2026-10-19) ni a los CUM aprobados con varios PLU
            // También cuentan los PLU nuevos de SIESA «por definir» (activos en SIESA, aún sin decisión en la Maestra Tarifaria)
            $plusActivos = array_map('strval', array_keys(array_filter($siesaLinea['_plus_mt'], fn($x) => $x === 1)));
            $pend = $siesaLinea['_plus_pend'];
            $varios = [...$plusActivos, ...$pend];
            if ($f !== '799' && _u($it['cum']) !== 'VITAL NO DISPONIBLE' && count($varios) > 1 && !nt_plu_varios_ok($variosAprobados, $d, $varios)) {
                $motivo('NT29', 'ERROR', "El CUM $d tiene " . count($varios) . ' PLU (' . implode(', ', $plusActivos) . ' activo' . (count($plusActivos) > 1 ? 's' : '') . ' en la Maestra Tarifaria'
                    . ($pend ? '; ' . implode(', ', $pend) . ' nuevo en SIESA, por definir' : '')
                    . '): dejar uno solo (inactivar el que sobra) o, si el CUM sí lleva varios, «Aprobar varios PLU»');
            }
            if ($cargaMedicar) {
                $mx = $medLinea;
                $donde = implode(' / ', $subplanesMed);
                if ($mx['medicar'] === 'NO') $motivo('NT26', 'ERROR', "No está creado en MEDICAR en el subplán $donde"
                    . ($mx['_plus'] ? ' (PLU ' . implode(', ', $mx['_plus']) . ')' : " (CUM $d)")
                    . ($mx['_otros'] ? '; está en MEDICAR en: ' . implode(', ', $mx['_otros']) : '') . ': crear en MEDICAR');
                // 7J (D-E2, D-E3, D-E5): se retira de la Maestra Tarifaria solo si está inactivo en SIESA y en MEDICAR, o si no tiene PLU en ninguno;
                // activo en SIESA e inactivo en MEDICAR es una corrección en MEDICAR (manda la Maestra Tarifaria, decisión C1)
                $activoSiesa = (bool) array_filter($itemsSiesa, fn($r) => _u($r['estado']) === 'ACTIVO');
                if ($mx['medicar'] === 'INACTIVO' && !$activoSiesa) $motivo('NT27', 'ERROR', "Inactivo en SIESA y en MEDICAR en el subplán $donde ({$mx['plu_medicar']}, Opción Cadena 9): retirar de la Maestra Tarifaria");
                elseif ($mx['medicar'] === 'INACTIVO') $motivo('NT26', 'ERROR', "Inactivo en MEDICAR en el subplán $donde ({$mx['plu_medicar']}, Opción Cadena 9) y activo en SIESA: activarlo en MEDICAR (ver Inconsistencias)");
                elseif ($mx['medicar'] === 'NO' && !$itemsSiesa && $cargas['MAESTRO_SIESA']) $motivo('NT30', 'ERROR', "Sin PLU en SIESA ni en MEDICAR: retirar de la Maestra Tarifaria");
            }
            if (!$itemsSiesa && $cargas['MAESTRO_SIESA']) $motivo('NT25', 'ERROR', $esCum
                ? "El CUM $d no está en el Maestro SIESA: crear el producto en SIESA"
                : "El código $d no está en el Maestro SIESA (MED-CODIGO GENERICO AUDIFARMA): crear el producto en SIESA");
        }
        if ((int) $it['activo']) foreach ($personalizadasNt as $rp) {
            $valorRp = $it[$rp['campo']] ?? $siesaLinea[$rp['campo']] ?? null; // digitado de la línea o dato de las bases (ATC, PLU…)
            if (!regla_cumple($rp, $valorRp)) $motivo($rp['codigo'], $rp['severidad'],
                str_replace('Maestra Tarifaria: ', '', $rp['descripcion']) . ' (' . (regla_campos()['NT'][$rp['campo']] ?? $rp['campo']) . ': ' . (($valorRp ?? '') !== '' ? $valorRp : 'vacío') . ')'
                . ($rp['accion_sugerida'] ? '. ' . $rp['accion_sugerida'] : ''));
        }
        if ($inactivasNt) $m = array_values(array_filter($m, fn($x) => !isset($inactivasNt[$x['codigo']])));
        $niveles = array_column($m, 'nivel');
        $estado = !(int) $it['activo'] ? 'INACTIVO' : (in_array('ERROR', $niveles, true) ? 'ERROR' : ($niveles ? 'CON ALERTA' : 'VALIDADO'));

        $fila = array_intersect_key($it, array_flip([...NT_CAMPOS, 'id', 'activo', 'origen_precio', 'actualizado_en', 'precio1_aprobado_en', 'cum_validado_en', 'precio_aplica', 'precio_digitado', 'fuente_precio',
            ...array_column(NT_DATOS_MT, 0)]));
        $porPlu = $siesaLinea['_por_plu'];
        // datos INVIMA / RIPS (2026-11-05): los de la Maestra Tarifaria mandan; vacío = SIESA (ATC, RIPS) o INVIMA (registro y estado)
        $invL = $invima[$d] ?? null;
        $fuenteDatos = ['atc' => $siesaLinea['atc'], 'ffm_rips' => $siesaLinea['ffm_rips'], 'umm_rips' => $siesaLinea['umm_rips'], 'upr_rips' => $siesaLinea['upr_rips'],
            'registro_sanitario' => $invL ? (trim((string) $invL['registro_sanitario']) ?: null) : null, 'estado_registro' => $invL ? (trim((string) $invL['estado_registro']) ?: null) : null];
        $siesaLinea['registro_sanitario'] = $fuenteDatos['registro_sanitario'];
        $siesaLinea['estado_registro'] = $fuenteDatos['estado_registro'];
        foreach (NT_DATOS_MT as $kd => [$col]) if (($it[$col] ?? null) !== null) { // $kd: $k es el contrato de la línea
            $siesaLinea[$kd] = $it[$col];
            foreach ($porPlu as &$x) $x[$kd] = $it[$col];
            unset($x);
        }
        $siesaLinea['datos_fuente'] = $fuenteDatos;
        $siesaLinea['descripcion_catalogo'] = $molCat[$e][0] ?? null;
        $siesaLinea['cod_gen_catalogo'] = $molCat[$e][1] ?? null;
        // clasificación de entrega: del catálogo COD GEN por el COD AUF (no se valida con SIESA ni MEDICAR)
        $siesaLinea['clasificacion_entrega'] = $molCat[$e][2] ?? null;
        // Un PLU por fila; el activar / inactivar es por PLU + CONTRATO: el PLU inactivo de una línea activa sale INACTIVO en su fila,
        // sin reglas (la línea se sigue validando con sus demás PLU)
        if (count($porPlu) > 1) foreach ($porPlu as $plu => &$x) {
            $x['plu'] = (string) $plu;
            $x['plu_de_varios'] = true; // fila de un PLU dentro de una línea con varios (el formulario activa / inactiva solo ese PLU)
            $x['plu_medicar'] = _nt_medicar([$plu], $d, $subplanesMed, $medPlu, $medCum)['plu_medicar'];
            // el PLU por definir conserva NT29 para resolverlo desde su fila (Aprobar varios PLU / Dejar inactivo)
            $pendX = in_array((string) $plu, $siesaLinea['_plus_pend'], true);
            $mPend = $pendX ? array_values(array_filter($m, fn($y) => $y['codigo'] === 'NT29')) : [];
            if ((int) $it['activo'] && !$x['_mt']) $x += ['activo' => 0, 'estado' => 'INACTIVO', 'motivos' => $mPend, 'reglas' => implode(' ', array_column($mPend, 'codigo')),
                'plu_inactivo' => true, 'plu_pendiente' => $pendX && $mPend];
        }
        unset($x, $siesaLinea['_plus'], $siesaLinea['_por_plu']);
        $filas[] = $fila + $siesaLinea + ['plu' => count($porPlu) === 1 ? (string) array_key_first($porPlu) : null, 'activo_linea' => (int) $it['activo'],
            'contrato_vista' => nt_contrato_vista($k), 'medicar' => $medLinea['medicar'], 'plu_medicar' => $medLinea['plu_medicar'],
            'subplan' => $subplanLinea, 'clasificacion' => $subplanLinea !== null ? ($subplanCat[$subplanLinea]['clasificacion'] ?? null) : null] + [
            'tarifa_capita' => $tarifaCapita,
            'precio_capita_ok' => $precioCapitaOk,
            'tipo_producto' => $f,
            'tipo_sugerido' => nt_tipo_producto($d),
            'cod_gen_siesa_calc' => $it['_h'],
            'precio_final' => $p,
            'precio_maximo' => $v,
            'precio_m_propio' => $mPorRegulado, // el M del grupo supera el regulado: se usa el precio de la línea
            'en_contrato' => $enContrato,
            'precio_contrato' => $precioContrato,
            'precio_correcto' => $precioCorrecto,
            'cum_codigo' => $cumCodigo,
            'precio_regulado' => $topeReg,
            'cumple_regulado' => $cumpleRegulado,
            'estado' => $estado,
            'motivos' => $m,
            'reglas' => implode(' ', array_unique(array_column($m, 'codigo'))),
            '_por_plu' => count($porPlu) > 1 ? array_values($porPlu) : [],
        ];
    }
    // guarda el estado de los PLU que entraron a la Maestra Tarifaria (o que se ajustaron solos)
    if ($pendPlu) {
        $propia = !$pdo->inTransaction();
        if ($propia) $pdo->beginTransaction();
        $up = $pdo->prepare('UPDATE nt_item_plu SET activo = ?, actualizado_en = ? WHERE nt_item_id = ? AND UPPER(plu) = ?');
        foreach ($pendPlu as [$idL, $pl, $v, $existe]) {
            if ($existe) $up->execute([$v, ahora(), $idL, $pl]);
            else insertar($pdo, 'nt_item_plu', ['nt_item_id' => $idL, 'plu' => $pl, 'activo' => $v, 'actualizado_por' => null, 'actualizado_en' => ahora()]);
        }
        if ($propia) $pdo->commit();
    }
    $faltan = [];
    foreach (['CONTRATO_EVENTO_22382' => 'Contrato Evento', 'LISTADO_CAPITA' => 'Listado Cápita', 'LISTADO_EVENTO_SAS' => 'Listado EVENTO SAS', 'MEDICAMENTOS_MUTUAL' => 'Maestra Mutual Ser',
              'INVIMA' => 'INVIMA', 'MAESTRO_SIESA' => 'Maestro SIESA', 'PRECIOS_REGULADOS' => 'Precios regulados'] as $b => $nombre) {
        if (!$cargas[$b]) $faltan[] = $nombre;
    }
    return $cache = ['filas' => $filas, 'bases_faltantes' => $faltan];
}

/** Consulta paginada con filtros por columna (sobre lo calculado) y resumen de toda la Maestra Tarifaria. */
function nt_listar(PDO $pdo, array $filtros, int $pagina, int $tam): array {
    $calc = nt_calcular($pdo);
    $todas = $calc['filas'];
    // no_contrato / glosa: la fila 1 del Excel ("VALIDACION FINAL (COD MUTUAL)" y "HAY n PRODUCTOS...")
    $resumen = ['total' => 0, 'VALIDADO' => 0, 'CON ALERTA' => 0, 'ERROR' => 0, 'INACTIVO' => 0, 'reglas' => [],
                'no_contrato' => 0, 'glosa' => 0, 'capita_precio' => 0, 'evento_sas_id' => 0, 'no_corresponde' => 0, 'capita_id' => 0,
                'contratos' => array_fill_keys(NT_CONTRATOS, 0), 'lineas_por_repetir' => count(nt_lineas_faltantes($pdo)),
                'precio_uno' => 0, 'precio_uno_pendiente' => 0,
                'inactivas_medicar' => count(array_filter($todas, fn($f) => (int) $f['activo'] && array_intersect(['NT27', 'NT30'], array_column($f['motivos'], 'codigo'))))];
    $porAgregar = array_count_values(array_column(nt_por_agregar($pdo), 'listado'));
    $resumen['capita_por_agregar'] = $porAgregar['CAPITA'] ?? 0;
    $resumen['evento_sas_por_agregar'] = $porAgregar['EVENTO SAS'] ?? 0;
    foreach ($todas as $f) {
        $resumen[$f['estado']]++;
        if ($f['estado'] !== 'INACTIVO') {
            $resumen['no_contrato'] += $f['en_contrato'] === 'NO' ? 1 : 0;
            $resumen['glosa'] += $f['precio_correcto'] === 'GLOSA TARIFA' ? 1 : 0;
            $resumen['capita_precio'] += $f['precio_capita_ok'] === 'NO' ? 1 : 0;
            $codigos = array_column($f['motivos'], 'codigo');
            $resumen['evento_sas_id'] += in_array('NT21', $codigos, true) ? 1 : 0;
            $resumen['no_corresponde'] += in_array('NT22', $codigos, true) ? 1 : 0;
            $resumen['capita_id'] += in_array('NT23', $codigos, true) ? 1 : 0;
            $resumen['contratos'][$f['contrato']] = ($resumen['contratos'][$f['contrato']] ?? 0) + 1;
            if ($f['precio_venta'] !== null && (float) $f['precio_venta'] <= 1) {
                $resumen['precio_uno']++;
                $resumen['precio_uno_pendiente'] += $f['precio1_aprobado_en'] ? 0 : 1;
            }
        }
        if ($f['estado'] !== 'INACTIVO') $resumen['total']++;
        foreach (array_unique(array_column($f['motivos'], 'codigo')) as $c) $resumen['reglas'][$c] = ($resumen['reglas'][$c] ?? 0) + 1;
    }
    ksort($resumen['reglas']);
    $sel = _nt_filtrar(nt_filas_por_plu($todas), $filtros);
    return ['total' => count($sel), 'pagina' => $pagina, 'tam' => $tam, 'filas' => array_slice($sel, ($pagina - 1) * $tam, $tam),
            'resumen' => $resumen, 'bases_faltantes' => $calc['bases_faltantes'],
            'reglas' => $pdo->query("SELECT codigo, descripcion, severidad, accion_sugerida FROM proc_regla WHERE codigo LIKE 'NT%' AND activa = 1 ORDER BY codigo")->fetchAll()];
}

/**
 * Datos del Maestro SIESA para una línea de la Maestra Tarifaria (cruce por CUM FACTURAR): REFERENCIA (es el PLU de Medicar)
 * y los códigos RIPS tal como están en SIESA. Se prefieren los ítems activos y, entre ellos, los del COD GEN SIESA de la
 * línea (prefijo de la referencia). Sin ítem activo se muestran los demás con su estado.
 */
function _nt_siesa_item(array $deLinea, array $est): array {
    // PLU que se muestran: los activos en la Maestra Tarifaria y los activos en SIESA (los inactivos en las dos son historia y no se muestran)
    $activosMt = array_filter($deLinea, fn($p) => ($est[$p] ?? 0) === 1, ARRAY_FILTER_USE_KEY);
    $vis = array_filter($deLinea, fn($r, $p) => ($est[$p] ?? 0) === 1 || _u($r['estado']) === 'ACTIVO', ARRAY_FILTER_USE_BOTH) ?: $deLinea;
    $usar = array_values($activosMt ?: $vis);
    $etq = fn($r) => $r['referencia'] . (_u($r['estado']) === 'ACTIVO' ? '' : ' (' . ucfirst(strtolower((string) $r['estado'])) . ' en SIESA)');
    $refs = array_unique(array_map($etq, $usar));
    $rips = function (string $campo) use ($usar) {
        $v = [];
        foreach ($usar as $r) {
            $c = trim((string) $r[$campo]);
            if ($c !== '') $v[$c] = $c;
        }
        return $v ? implode(' | ', $v) : null;
    };
    // cada PLU con sus propios datos: en la tabla y en el Excel va un PLU por fila (pedido del usuario, 2026-10-19)
    $porPlu = [];
    foreach ($vis as $plu => $r) {
        $porPlu[$plu] = ['referencia_siesa' => $etq($r),
            'ffm_rips' => trim((string) $r['ffm']) ?: null, 'umm_rips' => trim((string) $r['umm']) ?: null, 'upr_rips' => trim((string) $r['upr']) ?: null,
            'descripcion_siesa' => trim((string) $r['descripcion']) ?: null, 'atc' => trim((string) ($r['atc'] ?? '')) ?: null, '_mt' => ($est[$plu] ?? 0) === 1];
    }
    return ['_plus' => array_values(array_unique(array_map(fn($r) => _u($r['referencia']), $usar))), 'referencia_siesa' => $refs ? implode(', ', $refs) : null,
            '_plus_mt' => array_intersect_key($est, $deLinea), // estado en la Maestra Tarifaria de todos los PLU de la línea
            'ffm_rips' => $rips('ffm'), 'umm_rips' => $rips('umm'), 'upr_rips' => $rips('upr'),
            // DESCRIPCION COMPLETA: la descripción del ítem en el Maestro SIESA (la del PLU)
            'descripcion_siesa' => $rips('descripcion'), 'atc' => $rips('atc'), '_por_plu' => $porPlu];
}

/**
 * PLU de SIESA que son de la línea: los activos en SIESA (o todos, si no hay activos), prefiriendo los del COD GEN SIESA de la línea
 * (prefijo de la referencia), y los que están activos en la Maestra Tarifaria aunque SIESA los tenga inactivos. [PLU => ítem de SIESA]
 */
function _nt_plus_de_linea(array $items, string $codGen, array $est): array {
    $activos = array_values(array_filter($items, fn($r) => _u($r['estado']) === 'ACTIVO'));
    $usar = $activos ?: $items;
    $mismoGen = array_values(array_filter($usar, fn($r) => $codGen !== '' && explode('_', (string) $r['referencia'])[0] === $codGen));
    if ($mismoGen) $usar = $mismoGen;
    $out = [];
    foreach ($usar as $r) $out[_u($r['referencia'])] ??= $r;
    foreach ($items as $r) if (($est[_u($r['referencia'])] ?? 0) === 1) $out[_u($r['referencia'])] ??= $r;
    return $out;
}

/** PLU principal de una línea sin PLU activo: el del COD AUF / COD GEN de la línea (prefijo) o el primero. */
function _nt_plu_principal(array $plus, array $prefijos): string {
    foreach ($plus as $p) if (in_array(explode('_', (string) $p)[0], array_filter($prefijos), true)) return (string) $p;
    return (string) $plus[0];
}

/**
 * Un PLU por fila (pedido del usuario, 2026-10-19): la línea (CUM FACTURAR + contrato) se valida una sola vez; en la tabla,
 * los filtros y el Excel se repite por cada PLU de SIESA con su PLU, descripción, códigos RIPS y PLU de MEDICAR.
 */
function nt_filas_por_plu(array $filas): array {
    $out = [];
    foreach ($filas as $f) {
        $det = $f['_por_plu'] ?? [];
        unset($f['_por_plu']);
        if (count($det) <= 1) { $out[] = $f; continue; }
        foreach ($det as $d) $out[] = $d + $f;
    }
    return $out;
}

/** Subplanes de MEDICAR que corresponden a cada contrato de la Maestra Tarifaria. */
const NT_SUBPLAN_MEDICAR = ['CAPITA' => ['CAPITA PBS'], 'EVENTO' => ['EVENTO POS', 'EVENTO NO PBS'], 'EVENTO SAS' => ['EVENTO SAS SIN AUTORIZACION'], 'CONSORCIO' => ['CONSORCIO']];

/**
 * ¿La línea está en MEDICAR en el subplán de su contrato? Por los PLU de SIESA; si no hay PLU, por el CUM.
 * medicar: SI (activo), INACTIVO (solo con Opción Cadena 9) o NO (no está). plu_medicar: «PLU · SUBPLAN».
 */
function _nt_medicar(array $plus, string $cum, array $subplanes, array $medPlu, array $medCum): array {
    $hallados = []; // [plu, subplán, activo]
    if ($plus) {
        foreach ($plus as $plu) foreach ($subplanes as $sp) if (isset($medPlu[$plu][$sp])) $hallados[] = [$plu, $sp, $medPlu[$plu][$sp]];
    } else {
        foreach ($subplanes as $sp) foreach ($medCum[_u($cum)][$sp] ?? [] as $plu => $act) $hallados[] = [$plu, $sp, $act];
    }
    $activos = array_filter($hallados, fn($x) => $x[2]);
    $mostrar = $activos ?: $hallados;
    // subplanes en que sí está el PLU (para decir «está en MEDICAR en otro subplán»)
    $otros = [];
    foreach ($plus as $plu) foreach ($medPlu[$plu] ?? [] as $sp => $act) if (!in_array($sp, $subplanes, true)) $otros[$sp] = true;
    return ['medicar' => $activos ? 'SI' : ($hallados ? 'INACTIVO' : 'NO'), '_plus' => $plus, '_otros' => array_keys($otros),
            'plu_medicar' => $mostrar ? implode(', ', array_unique(array_map(fn($x) => "{$x[0]} · {$x[1]}", $mostrar))) : null];
}

/** Columnas de la Maestra Tarifaria que se pueden filtrar. */
const NT_FILTRABLES = ['contrato_vista', 'cum', 'cum_facturar', 'fecha', 'cod_auf', 'tipo_producto', 'clasificacion_entrega', 'descripcion', 'cod_gen_siesa', 'cod_mutual',
    'referencia_siesa', 'descripcion_siesa', 'atc', 'ffm_rips', 'umm_rips', 'upr_rips', 'medicar', 'plu_medicar', 'subplan', 'clasificacion',
    'precio_venta', 'iva', 'contrato', 'en_contrato', 'precio_correcto', 'cum_codigo', 'estado', 'reglas',
    'precio_final', 'precio_contrato', 'precio_maximo', 'precio_regulado', 'cumple_regulado', 'tarifa_capita', 'precio_capita_ok', 'activo_estado'];

/** Valor de una celda para filtrar y listar (precios sin decimales, vacío = ""). */
function _nt_celda(array $f, string $k): string {
    if ($k === 'activo_estado') return (int) ($f['activo'] ?? 0) ? 'ACTIVO' : 'INACTIVO'; // columna ESTADO: la línea o, en su fila, el PLU
    $v = $f[$k] ?? null;
    if ($v === null) return '';
    if (in_array($k, ['precio_venta', 'precio_final', 'precio_contrato', 'precio_maximo', 'precio_regulado'], true)) return (string) round((float) $v);
    if ($k === 'iva') return (string) (float) $v;
    return trim((string) $v);
}

/**
 * Filtros por columna como el autofiltro de Excel: lista de valores seleccionados (coincidencia exacta;
 * "" = vacías; en OBSERVACIONES, filas con alguna de las reglas). Un texto suelto filtra por coincidencia parcial.
 */
function _nt_filtrar(array $filas, array $filtros, ?string $excluir = null): array {
    $activos = [];
    foreach ($filtros as $k => $v) {
        if ($k === $excluir || !in_array($k, NT_FILTRABLES, true)) continue;
        if (is_array($v)) $activos[$k] = array_fill_keys(array_map(fn($x) => _u((string) $x), $v), true);
        elseif (trim((string) $v) !== '') $activos[$k] = _u((string) $v);
    }
    return array_values(array_filter($filas, function ($f) use ($activos) {
        foreach ($activos as $k => $v) {
            if ($k === 'reglas') {
                $codigos = array_column($f['motivos'], 'codigo');
                if (is_array($v) ? !array_intersect_key(array_flip($codigos), $v) && !(isset($v['']) && !$codigos) : !in_array($v, $codigos, true)) return false;
                continue;
            }
            $x = _u(_nt_celda($f, $k));
            if (is_array($v) ? !isset($v[$x]) : !str_contains($x, $v)) return false;
        }
        return true;
    }));
}

/**
 * Líneas que faltan en la Maestra Tarifaria: un producto (CUM a facturar) debe tener una línea por cada contrato donde está:
 *  - CAPITA: su COD AUF está en el Listado Cápita (precio = tarifa pactada, sin IVA);
 *  - EVENTO: su COD MUTUAL está en el Contrato Evento (precio = el del producto, con tope en el valor unitario del contrato);
 *  - EVENTO SAS: su CUM (o, si el listado no trae CUM, su COD AUF) está en el Listado EVENTO SAS (precio = el del producto, con tope en el contrato).
 * La línea nueva copia los datos de la línea existente del producto (decisión del usuario: precio de la base).
 */
function nt_lineas_faltantes(PDO $pdo): array {
    $capita = [];
    $st = $pdo->prepare('SELECT codigo_cup, tarifa_pactada FROM ref_capita WHERE carga_id = ? ORDER BY id');
    $st->execute([_carga_vigente($pdo, 'LISTADO_CAPITA')]);
    foreach ($st as $r) $capita[_u($r['codigo_cup'])] = (float) $r['tarifa_pactada'];
    $contrato = [];
    $st = $pdo->prepare('SELECT id_mutual, cod_servicio, valor_unitario FROM ref_contrato_evento WHERE carga_id = ? ORDER BY id');
    $st->execute([_carga_vigente($pdo, 'CONTRATO_EVENTO_22382')]);
    foreach ($st as $r) $contrato[_u($r['id_mutual'])][(string) $r['cod_servicio']] = (float) $r['valor_unitario'];
    $sasCum = []; $sasCod = [];
    $st = $pdo->prepare('SELECT cod_audifarma, cum FROM ref_evento_sas WHERE carga_id = ? ORDER BY id');
    $st->execute([_carga_vigente($pdo, 'LISTADO_EVENTO_SAS')]);
    foreach ($st as $r) { if ((string) $r['cum'] !== '') $sasCum[_u($r['cum'])] = true; else $sasCod[_u($r['cod_audifarma'])] = true; }
    $regulado = [];
    $st = $pdo->prepare('SELECT cod_gen, precio_regulado FROM ref_precio_regulado WHERE carga_id = ? ORDER BY id');
    $st->execute([_carga_vigente($pdo, 'PRECIOS_REGULADOS')]);
    foreach ($st as $r) $regulado[_u($r['cod_gen'])] = (float) $r['precio_regulado'];

    $porCum = [];
    foreach ($pdo->query('SELECT * FROM nt_item WHERE activo = 1 ORDER BY id') as $it) $porCum[_u($it['cum_facturar'])][$it['contrato']] ??= $it;
    $existe = array_fill_keys(array_map(fn($r) => _u($r[0]) . '|' . $r[1], $pdo->query('SELECT cum_facturar, contrato FROM nt_item')->fetchAll(PDO::FETCH_NUM)), true);
    // las líneas quitadas de la Maestra Tarifaria (nt_quitada) no se vuelven a proponer
    $existe += array_fill_keys(array_map(fn($r) => _u($r[0]) . '|' . $r[1], $pdo->query('SELECT cum_facturar, contrato FROM nt_quitada')->fetchAll(PDO::FETCH_NUM)), true);
    $faltan = [];
    foreach ($porCum as $cf => $lineas) {
        $base = reset($lineas);
        $e = _u($base['cod_auf']); $j = _u($base['cod_mutual']);
        $tipo = in_array((string) $base['tipo_producto'], ['714', '799'], true) ? (string) $base['tipo_producto'] : nt_tipo_producto($cf);
        $iva = (float) $base['iva'];
        $ctr = $j !== '' ? ($contrato[$j] ?? null) : null;
        $valorCtr = $ctr ? ($ctr[$tipo] ?? reset($ctr)) : null;
        $requeridos = array_filter([
            'CAPITA' => isset($capita[$e]) ? $capita[$e] : false,
            'EVENTO' => $ctr ? $valorCtr : false,
            'EVENTO SAS' => isset($sasCum[$cf]) || isset($sasCod[$e]) ? ($valorCtr ?? true) : false,
        ], fn($x) => $x !== false);
        foreach ($requeridos as $k => $valor) {
            if (isset($existe["$cf|$k"])) continue; // también si existe inactiva: no se vuelve a crear
            $fila = array_intersect_key($base, array_flip(NT_CAMPOS));
            $fila['contrato'] = $k;
            // CAPITA: la tarifa del Listado Cápita; si supera el precio regulado del COD AUF, se copia el PRECIO DE VENTA de la
            // línea original (decisión del usuario, 2026-09-29). EVENTO / EVENTO SAS: el PRECIO DE VENTA del producto (línea
            // original), con el contrato como tope (decisión del usuario, 2026-10-26).
            $propio = $base['precio_venta'] !== null ? (float) $base['precio_venta'] * (1 + $iva) : null;
            $usaBase = is_float($valor) && ($k === 'CAPITA'
                ? !(isset($regulado[$e]) && _nt_peso($valor) > _nt_peso($regulado[$e]) && $base['precio_venta'] !== null)
                : $propio === null || $propio > $valor + 0.5);
            $fila['precio_venta'] = $usaBase ? round(($k === 'CAPITA' ? _nt_peso($valor) : $valor) / (1 + $iva), 2) : $base['precio_venta'];
            $fila['origen_precio'] = $usaBase ? ($k === 'CAPITA' ? 'LISTADO CAPITA' : 'CONTRATO') : 'COPIA';
            $fila['_desde'] = $base['contrato'];
            $faltan[] = $fila;
        }
    }
    return $faltan;
}

/** Crea las líneas que faltan (ver nt_lineas_faltantes). Queda en auditoría. Devuelve cuántas creó. */
function nt_repetir_lineas(PDO $pdo, ?int $usuarioId): int {
    $faltan = nt_lineas_faltantes($pdo);
    if (!$faltan) return 0;
    $propia = !$pdo->inTransaction();
    if ($propia) $pdo->beginTransaction();
    try {
        $conteo = [];
        foreach ($faltan as $f) {
            $desde = $f['_desde']; unset($f['_desde']);
            insertar($pdo, 'nt_item', $f + ['fecha' => date('Y-m-d'), 'activo' => 1, 'creado_en' => ahora(), 'creado_por' => $usuarioId]);
            $conteo["$desde -> {$f['contrato']}"] = ($conteo["$desde -> {$f['contrato']}"] ?? 0) + 1;
        }
        auditar($usuarioId, 'NT_REPETIR_LINEAS', 'nt_item', null, null, ['lineas' => count($faltan), 'detalle' => $conteo]);
        if ($propia) $pdo->commit();
    } catch (Throwable $e) {
        if ($propia && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return count($faltan);
}

/**
 * CUM no encontrado en INVIMA validado a mano (NT10): se marca en todas las líneas de ese CUM a facturar.
 * $validar = false retira la validación. Solo el Administrador; queda en auditoría.
 */
function nt_validar_cum(PDO $pdo, string $cumFacturar, bool $validar, int $usuarioId): int {
    $st = $pdo->prepare('SELECT id, contrato FROM nt_item WHERE cum_facturar = ?');
    $st->execute([$cumFacturar]);
    $lineas = $st->fetchAll();
    if (!$lineas) throw new ErrorApi('CUM a facturar no encontrado en la Maestra Tarifaria.', 404);
    $pdo->prepare('UPDATE nt_item SET cum_validado_por = ?, cum_validado_en = ? WHERE cum_facturar = ?')
        ->execute([$validar ? $usuarioId : null, $validar ? ahora() : null, $cumFacturar]);
    auditar($usuarioId, $validar ? 'NT_CUM_VALIDAR' : 'NT_CUM_RETIRAR', 'nt_item', $cumFacturar, null, ['contratos' => array_column($lineas, 'contrato')]);
    return count($lineas);
}

/**
 * Quita de la Maestra Tarifaria las líneas inactivas en MEDICAR en su subplán (NT27). Decisión del usuario (2026-10-11):
 * no quedan como inactivas; antes de quitarlas la Maestra Tarifaria completa se guarda como VERSIÓN ANTERIOR (Excel en el historial
 * de versiones) y cada línea queda en nt_quitada y en auditoría (no se vuelve a proponer).
 */
function nt_quitar_medicar(PDO $pdo, int $usuarioId): array {
    $quitar = [];
    foreach (nt_calcular($pdo, true)['filas'] as $f) {
        if ((int) $f['activo'] && array_intersect(['NT27', 'NT30'], array_column($f['motivos'], 'codigo'))) $quitar[(int) $f['id']] = $f;
    }
    if (!$quitar) return ['lineas' => 0, 'version' => null];
    $motivo = 'Inactiva en SIESA y MEDICAR (NT27) o sin PLU en ninguno (NT30)';
    $v = nt_guardar_version($pdo, "Versión anterior: antes de retirar " . count($quitar) . " líneas inactivas en SIESA y MEDICAR o sin PLU", $usuarioId,
        ['motivo' => $motivo, 'quitadas' => array_values(array_map(fn($f) => "{$f['cum_facturar']} ({$f['contrato']}) {$f['plu_medicar']}", $quitar))]);
    $st = $pdo->prepare('SELECT * FROM nt_item WHERE id = ?');
    foreach ($quitar as $id => $f) {
        $st->execute([$id]);
        $fila = $st->fetch();
        insertar($pdo, 'nt_quitada', ['cum_facturar' => $fila['cum_facturar'], 'contrato' => $fila['contrato'], 'cod_auf' => $fila['cod_auf'],
            'motivo' => (in_array('NT30', array_column($f['motivos'], 'codigo'), true) ? 'Sin PLU en SIESA ni en MEDICAR (NT30)' : "Inactiva en SIESA y MEDICAR (NT27): {$f['plu_medicar']}"), 'carga_id' => $v['carga_id'], 'datos' => json_encode($fila, JSON_UNESCAPED_UNICODE),
            'quitado_por' => $usuarioId, 'quitado_en' => ahora()]);
        $pdo->prepare('DELETE FROM nt_precio_calculo WHERE nt_item_id = ?')->execute([$id]);
        $pdo->prepare('UPDATE nt_producto SET nt_item_id = NULL WHERE nt_item_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM nt_item WHERE id = ?')->execute([$id]);
        auditar($usuarioId, 'NT_QUITAR', 'nt_item', $id, $fila, ['motivo' => "$motivo: {$f['plu_medicar']}", 'version_anterior' => $v['version']]);
    }
    return ['lineas' => count($quitar), 'version' => $v['version']];
}

/** Guarda la Maestra Tarifaria actual (exportación completa) como una versión del historial de la Maestra Tarifaria. */
function nt_guardar_version(PDO $pdo, string $origen, int $usuarioId, array $detalle = []): array {
    $version = (int) $pdo->query("SELECT COALESCE(MAX(version), 0) FROM ref_carga WHERE tipo_base = 'NOTA_TECNICA'")->fetchColumn() + 1;
    $dir = __DIR__ . '/../data/bases/NOTA_TECNICA';
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new RuntimeException("No se pudo crear $dir");
    $destino = sprintf('%s/v%03d_%s.xlsx', $dir, $version, date('Ymd_His'));
    nt_exportar($pdo, $destino);
    $lineas = (int) $pdo->query('SELECT COUNT(*) FROM nt_item WHERE activo = 1')->fetchColumn();
    $cargaId = insertar($pdo, 'ref_carga', ['tipo_base' => 'NOTA_TECNICA', 'origen' => mb_substr($origen, 0, 300), 'hash_sha256' => hash_file('sha256', $destino),
        'filas' => $lineas, 'estado' => 'VERSION_ANTERIOR', 'vigente' => 0, 'version' => $version, 'archivo' => 'data/bases/NOTA_TECNICA/' . basename($destino),
        'tamano_bytes' => filesize($destino), 'detalle' => json_encode($detalle, JSON_UNESCAPED_UNICODE), 'usuario_id' => $usuarioId, 'creado_en' => ahora()]);
    auditar($usuarioId, 'NT_VERSION', 'ref_carga', $cargaId, null, ['version' => $version, 'origen' => $origen, 'lineas' => $lineas]);
    return ['carga_id' => $cargaId, 'version' => $version];
}

/** Acciones de la auditoría que cambian la Maestra Tarifaria (no se incluyen las descargas). */
const NT_ACCIONES_CAMBIO = ['NT_CREAR', 'NT_EDITAR', 'NT_QUITAR', 'NT_REPETIR_LINEAS', 'NT_PLANTILLA_AGREGAR', 'NT_PRECIO1_APROBAR', 'NT_PRECIO1_RETIRAR',
    'NT_PRECIO_LINEA_ORIGINAL', 'NT_CUM_VALIDAR', 'NT_CUM_RETIRAR', 'NT_INCLUIR', 'NT_IMPORTAR', 'NT_VERSION'];
const NT_ACCIONES_NOMBRE = ['NT_CREAR' => 'Creada', 'NT_EDITAR' => 'Editada', 'NT_QUITAR' => 'Quitada', 'NT_REPETIR_LINEAS' => 'Línea creada en otro contrato',
    'NT_PLANTILLA_AGREGAR' => 'Agregada desde listados', 'NT_PRECIO1_APROBAR' => 'Precio 1 aprobado', 'NT_PRECIO1_RETIRAR' => 'Precio 1 retirado',
    'NT_PRECIO_LINEA_ORIGINAL' => 'Precio de la línea original', 'NT_CUM_VALIDAR' => 'CUM validado a mano', 'NT_CUM_RETIRAR' => 'Validación de CUM retirada',
    'NT_INCLUIR' => 'Incluida', 'NT_IMPORTAR' => 'Importación desde Excel', 'NT_VERSION' => 'Versión guardada'];

/** Campo por campo de un registro de auditoría (antes / después en JSON): [[campo, antes, después], ...]. */
function aud_expandir(?string $antes, ?string $despues): array {
    $a = json_decode((string) $antes, true); $d = json_decode((string) $despues, true);
    $txt = fn($v) => $v === null ? null : (is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE));
    if (!is_array($a) && !is_array($d)) return [[null, $antes, $despues]];
    $a = is_array($a) ? $a : []; $d = is_array($d) ? $d : [];
    $out = [];
    foreach (array_unique([...array_keys($a), ...array_keys($d)]) as $k) {
        if (array_key_exists($k, $a) && array_key_exists($k, $d) && $txt($a[$k]) === $txt($d[$k])) continue;
        $out[] = [(string) $k, $txt($a[$k] ?? null), $txt($d[$k] ?? null)];
    }
    return $out ?: [[null, null, null]];
}

/**
 * Cambios de la Maestra Tarifaria (auditoría): por fecha, línea (CUM FACTURAR y contrato), campo, antes y después.
 * $desde / $hasta en AAAA-MM-DD (opcionales). Generador para no cargar todo en memoria al exportar.
 */
function nt_cambios(PDO $pdo, ?string $desde = null, ?string $hasta = null, ?int $limite = null): Generator {
    $lineas = [];
    foreach ($pdo->query('SELECT id, cum_facturar, contrato FROM nt_item') as $r) $lineas[(int) $r['id']] = [$r['cum_facturar'], $r['contrato']];
    $cond = ['l.accion IN (' . implode(',', array_fill(0, count(NT_ACCIONES_CAMBIO), '?')) . ')']; $p = NT_ACCIONES_CAMBIO;
    if ($desde) { $cond[] = 'l.creado_en >= ?'; $p[] = "$desde 00:00:00"; }
    if ($hasta) { $cond[] = 'l.creado_en <= ?'; $p[] = "$hasta 23:59:59"; }
    $st = $pdo->prepare('SELECT l.id, l.creado_en, u.nombre AS usuario, l.accion, l.entidad, l.entidad_id, l.antes, l.despues FROM aud_log l
                          LEFT JOIN seg_usuario u ON u.id = l.usuario_id WHERE ' . implode(' AND ', $cond) . ' ORDER BY l.id DESC' . ($limite ? " LIMIT $limite" : ''));
    $st->execute($p);
    foreach ($st as $r) {
        $a = json_decode((string) $r['antes'], true) ?: []; $d = json_decode((string) $r['despues'], true) ?: [];
        [$cf, $ctr] = $r['entidad'] === 'nt_item' ? ($lineas[(int) $r['entidad_id']] ?? [$a['cum_facturar'] ?? $d['cum_facturar'] ?? (is_numeric($r['entidad_id']) ? null : $r['entidad_id']), $a['contrato'] ?? $d['contrato'] ?? null]) : [null, null];
        $base = [$r['creado_en'], $r['usuario'], NT_ACCIONES_NOMBRE[$r['accion']] ?? $r['accion'], $cf, $ctr === 'EVENTO SAS' ? 'EVENTO · ' . NT_SUBPLAN_SAS : $ctr];
        if (in_array($r['accion'], ['NT_QUITAR', 'NT_CREAR', 'NT_IMPORTAR', 'NT_VERSION'], true)) {
            // registro completo: un resumen en una sola fila
            $res = $r['accion'] === 'NT_QUITAR' ? ($d['motivo'] ?? '') : ($r['accion'] === 'NT_CREAR' ? 'Precio ' . ($d['precio_venta'] ?? '') . ' · COD MUTUAL ' . ($d['cod_mutual'] ?? '')
                : implode(' · ', array_map(fn($k, $v) => "$k: " . (is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE)), array_keys($d), $d)));
            yield [...$base, null, null, mb_substr((string) $res, 0, 500)];
            continue;
        }
        foreach (aud_expandir($r['antes'], $r['despues']) as [$campo, $antes, $despues]) yield [...$base, $campo, $antes, $despues];
    }
}

/**
 * Foto diaria (pedido del usuario, 2026-10-11: «cómo era la Maestra Tarifaria en una fecha»). Antes del PRIMER cambio de cada día se
 * guarda la Maestra Tarifaria completa como versión (si cambió desde la última versión). Así, la Maestra Tarifaria al cierre de una fecha D es la
 * primera versión guardada después de D (se tomó antes de cualquier cambio posterior a D); si no hay ninguna y no hubo
 * cambios después de D, es la Maestra Tarifaria actual.
 */
function nt_foto_del_dia(PDO $pdo, int $usuarioId): ?array {
    $hoy = date('Y-m-d');
    $st = $pdo->prepare("SELECT 1 FROM ref_carga WHERE tipo_base = 'NOTA_TECNICA' AND estado = 'VERSION_ANTERIOR' AND creado_en >= ? LIMIT 1");
    $st->execute(["$hoy 00:00:00"]);
    if ($st->fetchColumn()) return null; // ya hay versión hoy
    if (!(int) $pdo->query('SELECT COUNT(*) FROM nt_item')->fetchColumn()) return null;
    $ultVersion = $pdo->query("SELECT MAX(creado_en) FROM ref_carga WHERE tipo_base = 'NOTA_TECNICA' AND estado = 'VERSION_ANTERIOR'")->fetchColumn();
    $p = NT_ACCIONES_CAMBIO;
    $st = $pdo->prepare('SELECT MAX(creado_en) FROM aud_log WHERE accion IN (' . implode(',', array_fill(0, count($p), '?')) . ')');
    $st->execute($p);
    $ultCambio = $st->fetchColumn();
    if ($ultVersion && $ultCambio && $ultCambio < $ultVersion) return null; // no cambió desde la última versión
    return nt_guardar_version($pdo, 'Foto diaria: Maestra Tarifaria al ' . ($ultCambio ? substr((string) $ultCambio, 0, 16) : ahora()) . ' (antes de los cambios del ' . $hoy . ')',
        $usuarioId, ['tipo' => 'FOTO_DIARIA']);
}

/**
 * Maestra Tarifaria tal como estaba al cierre de una fecha: ['ruta', 'nombre', 'origen', 'temporal'] o error si esa fecha
 * no se puede reconstruir (antes de que empezaran las fotos diarias).
 */
function nt_al_corte(PDO $pdo, string $fecha): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) throw new ErrorApi('Fecha no válida.', 422);
    $primera = $pdo->query("SELECT MIN(creado_en) FROM ref_carga WHERE tipo_base = 'NOTA_TECNICA'")->fetchColumn();
    if (!$primera || $fecha < substr((string) $primera, 0, 10)) throw new ErrorApi('La Maestra Tarifaria se cargó al aplicativo el ' . ($primera ? substr((string) $primera, 0, 10) : '—') . ': antes de esa fecha no hay datos.', 404);
    $nombre = "MAESTRA TARIFARIA AL $fecha.xlsx";
    $st = $pdo->prepare("SELECT id, version, origen, creado_en FROM ref_carga WHERE tipo_base = 'NOTA_TECNICA' AND estado = 'VERSION_ANTERIOR' AND archivo IS NOT NULL
                          AND creado_en > ? ORDER BY creado_en, id LIMIT 1");
    $st->execute(["$fecha 23:59:59"]);
    $v = $st->fetch();
    // la foto vale para esa fecha solo si no hubo cambios entre el cierre de la fecha y el momento de la foto
    // (el primer día de las fotos, la foto se tomó después de cambios de ese mismo día)
    $p = NT_ACCIONES_CAMBIO;
    $hubo = function (string $hasta) use ($pdo, $fecha, $p) {
        $st = $pdo->prepare('SELECT 1 FROM aud_log WHERE creado_en > ? AND creado_en < ? AND accion IN (' . implode(',', array_fill(0, count($p), '?')) . ') LIMIT 1');
        $st->execute(["$fecha 23:59:59", $hasta, ...$p]);
        return (bool) $st->fetchColumn();
    };
    if ($v && !$hubo((string) $v['creado_en'])) return ['ruta' => nt_version_ruta($pdo, (int) $v['id']), 'nombre' => $nombre, 'temporal' => false,
        'origen' => "versión v{$v['version']} guardada el " . substr((string) $v['creado_en'], 0, 16)];
    if ($v || $hubo('9999-12-31')) {
        $desde = $pdo->query("SELECT MIN(creado_en) FROM ref_carga WHERE tipo_base = 'NOTA_TECNICA' AND estado = 'VERSION_ANTERIOR'")->fetchColumn();
        throw new ErrorApi("No hay una foto de la Maestra Tarifaria para el $fecha: hubo cambios después y las fotos diarias empezaron "
            . ($desde ? 'el ' . substr((string) $desde, 0, 10) : 'hoy') . '. Desde entonces se puede descargar cualquier fecha.', 404);
    }
    $tmp = tempnam(sys_get_temp_dir(), 'ntf');
    nt_exportar($pdo, $tmp);
    return ['ruta' => $tmp, 'nombre' => $nombre, 'temporal' => true, 'origen' => 'NT actual (no hubo cambios después de esa fecha)'];
}

/** Historial de versiones de la Maestra Tarifaria: importaciones y versiones anteriores guardadas al quitar líneas. */
/** Cambios de la Maestra Tarifaria que puede ver el usuario: sin PRECIO.VER se omiten los de precio. */
function nt_cambios_visibles(iterable $filas, bool $conPrecios): Generator {
    foreach ($filas as $r) {
        if (!$conPrecios) {
            if (in_array($r[5] ?? null, NT_CAMPOS_PRECIO, true) || stripos((string) $r[2], 'precio') !== false) continue;
            if ($r[5] === null && $r[7] !== null) $r[7] = preg_replace('/Precio\s+[\d.,]+\s*·?\s*/u', '', (string) $r[7]);
        }
        yield $r;
    }
}

function nt_versiones(PDO $pdo): array {
    $filas = $pdo->query("SELECT c.id, c.version, c.origen, c.estado, c.filas, c.archivo, c.creado_en, c.detalle, u.nombre AS usuario
                            FROM ref_carga c LEFT JOIN seg_usuario u ON u.id = c.usuario_id
                           WHERE c.tipo_base = 'NOTA_TECNICA' AND c.estado IN ('APLICADA', 'VERSION_ANTERIOR') ORDER BY c.version DESC, c.id DESC")->fetchAll();
    foreach ($filas as &$f) {
        $d = json_decode((string) $f['detalle'], true) ?: [];
        $f['tipo'] = $f['estado'] !== 'VERSION_ANTERIOR' ? 'Importación desde Excel' : (($d['tipo'] ?? '') === 'FOTO_DIARIA' ? 'Foto diaria' : 'Versión anterior (respaldo)');
        $f['quitadas'] = $d['quitadas'] ?? [];
        $f['descargable'] = $f['archivo'] && is_file(__DIR__ . '/../' . $f['archivo']);
        unset($f['detalle'], $f['archivo']);
    }
    return $filas;
}

function nt_version_ruta(PDO $pdo, int $id): string {
    $st = $pdo->prepare("SELECT archivo FROM ref_carga WHERE id = ? AND tipo_base = 'NOTA_TECNICA'");
    $st->execute([$id]);
    $a = $st->fetchColumn();
    $ruta = $a ? realpath(__DIR__ . '/../' . $a) : false;
    if (!$ruta || !str_starts_with($ruta, realpath(__DIR__ . '/../data/bases/NOTA_TECNICA'))) throw new ErrorApi('El archivo de esa versión no está disponible.', 404);
    return $ruta;
}

/** Líneas con precio de venta 1 (excepción que aprueba el Administrador): todas en una sola lista. */
function nt_precio_uno(PDO $pdo): array {
    return $pdo->query("SELECT n.id, n.contrato, n.cum_facturar, n.cod_auf, n.descripcion, n.cod_mutual, n.precio_venta, n.activo,
                               n.precio1_aprobado_en, u.nombre AS aprobado_por
                          FROM nt_item n LEFT JOIN seg_usuario u ON u.id = n.precio1_aprobado_por
                         WHERE n.precio_venta IS NOT NULL AND n.precio_venta <= 1
                         ORDER BY n.precio1_aprobado_en IS NOT NULL, n.descripcion, n.contrato")->fetchAll();
}

/** Aprueba (o retira la aprobación de) la excepción de precio 1 en las líneas indicadas. Queda en auditoría. */
function nt_precio_uno_aprobar(PDO $pdo, array $ids, bool $aprobar, int $usuarioId): int {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) throw new ErrorApi('Seleccione al menos una línea.', 422);
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, contrato, cum_facturar, precio_venta FROM nt_item WHERE id IN ($marcas) AND precio_venta IS NOT NULL AND precio_venta <= 1");
    $st->execute($ids);
    $lineas = $st->fetchAll();
    if (count($lineas) !== count($ids)) throw new ErrorApi('Solo se pueden aprobar líneas con precio de venta 1.', 422);
    $pdo->prepare("UPDATE nt_item SET precio1_aprobado_por = ?, precio1_aprobado_en = ? WHERE id IN ($marcas)")
        ->execute([$aprobar ? $usuarioId : null, $aprobar ? ahora() : null, ...$ids]);
    auditar($usuarioId, $aprobar ? 'NT_PRECIO1_APROBAR' : 'NT_PRECIO1_RETIRAR', 'nt_item', null, null,
        ['lineas' => array_map(fn($l) => "{$l['cum_facturar']} ({$l['contrato']})", $lineas)]);
    return count($ids);
}

/** Columnas que se diligencian en la Maestra Tarifaria (mismo orden y nombres de la hoja EVENTO 2025). */
/** Columnas de «Por agregar» (pantalla y plantilla): CONTRATO es CAPITA / EVENTO y el SUBPLAN distingue la línea SAS. */
function nt_columnas_por_agregar(): array {
    $c = nt_columnas_digitables(); unset($c['contrato']);
    return ['contrato_vista' => 'CONTRATO', 'subplan' => 'SUBPLAN'] + $c;
}
function nt_subplan_vista(?string $linea): ?string { return $linea === 'EVENTO SAS' ? NT_SUBPLAN_SAS : ($linea === 'CAPITA' ? 'CAPITA PBS' : null); }

function nt_columnas_digitables(): array {
    return ['contrato' => 'CONTRATO', 'fecha' => 'FECHA', 'cum' => 'CUM', 'cum_facturar' => 'CUM FACTURAR', 'cod_auf' => 'COD AUF',
            'tipo_producto' => 'TIPO PRODUCTO', 'descripcion' => 'DESCRIPCION', 'cod_gen_siesa' => 'COD GEN SIESA', 'cod_mutual' => 'COD MUTUAL',
            'precio_venta' => 'PRECIO DE VENTA ' . parametro('NT_ANIO_PRECIO', '2026'), 'iva' => 'IVA']; // COD MUTUAL FINAL retirado (7H, 2026-10-11)
}

/**
 * Por AGREGAR: productos de los listados que no están en la Maestra Tarifaria. Se entregan con las columnas de
 * la Maestra Tarifaria, prellenadas con lo que se conoce; el usuario completa lo que falte (columna OBSERVACION).
 *  - Listado Cápita: Código CUP sin ninguna fila activa con ese COD AUF (precio = tarifa pactada).
 *  - Listado EVENTO SAS: CUM que no es CUM FACTURAR de la Maestra Tarifaria o, si la fila no trae CUM, Cód. Audifarma sin
 *    fila activa con ese COD AUF (precio = el del Contrato Evento sin IVA, si el ID MUTUAL está allí).
 */
function nt_por_agregar(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $st = $pdo->prepare("SELECT codigo_cup, id_mutual, cum, descripcion_cups, codigo_servicio, tarifa_pactada
                           FROM ref_capita WHERE carga_id = ?
                            AND UPPER(codigo_cup) NOT IN (SELECT UPPER(cod_auf) FROM nt_item WHERE activo = 1 AND cod_auf IS NOT NULL)
                            AND UPPER(codigo_cup) NOT IN (SELECT UPPER(cod_auf) FROM nt_quitada WHERE contrato = 'CAPITA' AND cod_auf IS NOT NULL)
                          ORDER BY descripcion_cups");
    $st->execute([_carga_vigente($pdo, 'LISTADO_CAPITA')]);
    $faltanCapita = $st->fetchAll();
    $st = $pdo->prepare("SELECT DISTINCT cod_audifarma, cum, id_mutual, descripcion FROM ref_evento_sas e WHERE carga_id = ?
                            AND CASE WHEN cum IS NOT NULL AND cum <> ''
                                     THEN UPPER(cum) NOT IN (SELECT UPPER(cum_facturar) FROM nt_item WHERE activo = 1)
                                          AND UPPER(cum) NOT IN (SELECT UPPER(cum_facturar) FROM nt_quitada WHERE contrato = 'EVENTO SAS')
                                     ELSE UPPER(cod_audifarma) NOT IN (SELECT UPPER(cod_auf) FROM nt_item WHERE activo = 1 AND cod_auf IS NOT NULL) END
                          ORDER BY descripcion");
    $st->execute([_carga_vigente($pdo, 'LISTADO_EVENTO_SAS')]);
    $faltanSas = $st->fetchAll();
    // Maestro SIESA por CUM: COD GEN SIESA (prefijo de la referencia) e IVA (grupo impositivo)
    $siesa = [];
    $st = $pdo->prepare("SELECT cum, referencia, grupo_impositivo FROM ref_siesa_item WHERE carga_id = ? AND cum IS NOT NULL AND cum <> '' ORDER BY id");
    $st->execute([_carga_vigente($pdo, 'MAESTRO_SIESA')]);
    foreach ($st as $r) $siesa[$r['cum']] ??= [explode('_', (string) $r['referencia'])[0], $r['grupo_impositivo']];
    $iva = [];
    foreach ($pdo->query('SELECT codigo, iva_medicar FROM cat_grupo_impositivo') as $g) $iva[$g['codigo']] = ((float) $g['iva_medicar']) / 100;
    $contrato = [];
    $st = $pdo->prepare('SELECT id_mutual, cod_servicio, valor_unitario FROM ref_contrato_evento WHERE carga_id = ? ORDER BY id');
    $st->execute([_carga_vigente($pdo, 'CONTRATO_EVENTO_22382')]);
    foreach ($st as $r) $contrato[_u($r['id_mutual'])][(string) $r['cod_servicio']] = (float) $r['valor_unitario'];
    $enNt = array_fill_keys(array_map('_u', $pdo->query('SELECT cum_facturar FROM nt_item')->fetchAll(PDO::FETCH_COLUMN)), true);

    $filas = [];
    $fila = function (string $listado, array $x, array $pendiente) use (&$filas, $enNt) {
        $cf = (string) $x['cum_facturar'];
        $filas[] = $x + ['listado' => $listado, 'observacion' => trim(($cf !== '' && isset($enNt[_u($cf)]) ? "El CUM a facturar $cf ya está en la Maestra Tarifaria con otro COD AUF. " : '')
            . ($pendiente ? 'Completar: ' . implode(', ', $pendiente) . '.' : ''))];
    };
    foreach ($faltanCapita as $r) {
        $cum = (string) $r['cum'];
        $esCum = (bool) preg_match('/^\d+-\d+$/', $cum);
        $cumFacturar = $esCum ? $cum : _u($r['id_mutual']);
        $sie = $esCum ? ($siesa[$cum] ?? null) : null;
        $fila('CAPITA', [
            'contrato' => 'CAPITA', 'fecha' => date('Y-m-d'), 'cum' => $cum, 'cum_facturar' => $cumFacturar, 'cod_auf' => _u($r['codigo_cup']),
            'tipo_producto' => in_array((string) $r['codigo_servicio'], ['714', '799'], true) ? (string) $r['codigo_servicio'] : nt_tipo_producto($cumFacturar),
            'descripcion' => $r['descripcion_cups'], 'cod_gen_siesa' => $sie[0] ?? null, 'cod_mutual' => _u($r['id_mutual']),
            'precio_venta' => round((float) $r['tarifa_pactada'] / (1 + ($sie !== null ? ($iva[$sie[1]] ?? 0) : 0)), 2),
            'iva' => $sie !== null ? ($iva[$sie[1]] ?? 0) : 0,
        ], array_keys(array_filter(['COD GEN SIESA' => $sie === null, 'CUM' => !$esCum])));
    }
    $yaCapita = array_fill_keys(array_map(fn($r) => _u($r['codigo_cup']), $faltanCapita), true);
    foreach ($faltanSas as $r) {
        $cod = _u($r['cod_audifarma']); $id = _u($r['id_mutual']); $cum = _u($r['cum']);
        if ($cum === '' && isset($yaCapita[$cod])) continue; // ya sale por el Listado Cápita
        $esCum = (bool) preg_match('/^\d+-\d+$/', $cum);
        // Sin CUM: si el ID MUTUAL es el mismo Cód. Audifarma es un insumo; en la Maestra Tarifaria su CUM y CUM FACTURAR son ese código.
        $insumo = $cum === '' && $id !== '' && $id === $cod;
        if ($insumo) $cum = $cod;
        $cumFacturar = $cum !== '' ? $cum : null;
        $sie = $esCum ? ($siesa[$cum] ?? null) : null;
        $ctr = $id !== '' ? ($contrato[$id] ?? null) : null;
        $tipo = $ctr && count($ctr) === 1 ? (string) array_key_first($ctr)
            : ($cumFacturar !== null ? nt_tipo_producto($cumFacturar) : (str_starts_with($id, 'M') ? '714' : '799'));
        $ivaFila = $sie !== null ? ($iva[$sie[1]] ?? 0) : 0;
        $valor = $ctr[$tipo] ?? null;
        $fila('EVENTO SAS', [
            'contrato' => 'EVENTO SAS', 'fecha' => date('Y-m-d'), 'cum' => $cum !== '' ? $cum : null, 'cum_facturar' => $cumFacturar, 'cod_auf' => $cod,
            'tipo_producto' => $tipo, 'descripcion' => $r['descripcion'], 'cod_gen_siesa' => $sie[0] ?? null,
            'cod_mutual' => $id !== '' ? $id : null,
            'precio_venta' => $valor !== null ? round($valor / (1 + $ivaFila), 2) : null, 'iva' => $ivaFila,
        ], array_keys(array_filter(['CUM' => $cum === '', 'CUM FACTURAR' => $cumFacturar === null, 'COD MUTUAL' => $id === '',
            'COD GEN SIESA' => $sie === null, 'PRECIO DE VENTA (el ID MUTUAL no está en el contrato)' => $valor === null && $id !== ''])));
    }
    return $cache = $filas;
}

/** Excel con los códigos por agregar (columnas de la Maestra Tarifaria) para diligenciarlo y cargarlo con «Importar desde Excel». */
function nt_por_agregar_plantilla(PDO $pdo, string $destino): string {
    $cols = nt_columnas_por_agregar();
    $filas = (function () use ($pdo, $cols) {
        foreach (nt_por_agregar($pdo) as $f) {
            $f['contrato_vista'] = nt_contrato_vista($f['contrato']); $f['subplan'] = nt_subplan_vista($f['contrato']);
            yield [...array_map(fn($k) => in_array($k, ['precio_venta', 'iva'], true) ? ($f[$k] === null ? null : (float) $f[$k]) : ($f[$k] === null ? null : (string) $f[$k]), array_keys($cols)),
                   $f['listado'], $f['observacion'] ?: null];
        }
    })();
    XlsxWriter::guardar($destino, [['nombre' => 'EVENTO 2025', 'encabezados' => [...array_values($cols), 'LISTADO (no se carga)', 'OBSERVACION (no se carga)'], 'filas' => $filas]]);
    return 'MAESTRA TARIFARIA - POR AGREGAR DESDE LISTADOS - ' . date('Ymd') . '.xlsx';
}

/** Valores distintos de una columna (lista del autofiltro), con los demás filtros aplicados. */
function nt_valores(PDO $pdo, array $filtros, string $campo, string $busqueda): array {
    if (!in_array($campo, NT_FILTRABLES, true)) throw new ErrorApi('Columna no válida.', 422);
    $filas = _nt_filtrar(nt_filas_por_plu(nt_calcular($pdo)['filas']), $filtros, $campo);
    $b = _u($busqueda);
    $v = [];
    foreach ($filas as $f) {
        foreach ($campo === 'reglas' ? (array_unique(array_column($f['motivos'], 'codigo')) ?: ['']) : [_nt_celda($f, $campo)] as $x) {
            if ($b === '' || str_contains(_u($x), $b)) $v[$x] = true;
        }
    }
    $v = array_keys($v);
    usort($v, fn($a, $c) => $a === '' ? 1 : ($c === '' ? -1 : strnatcasecmp((string) $a, (string) $c)));
    return ['valores' => array_slice(array_map('strval', $v), 0, 1000), 'mas' => count($v) > 1000];
}

/** Normaliza y valida los datos digitados de una fila. */
function _nt_normalizar(array $d, bool $nuevo): array {
    $txt = fn($k) => ($v = trim(preg_replace('/\s+/u', ' ', (string) ($d[$k] ?? '')))) === '' ? null : mb_strtoupper($v);
    $out = [];
    foreach (['cum', 'cod_auf', 'descripcion', 'cod_gen_siesa', 'cod_mutual'] as $k) $out[$k] = $txt($k);
    if ($nuevo) {
        $out['cum_facturar'] = $txt('cum_facturar');
        if (!$out['cum_facturar']) throw new ErrorApi('El CUM a facturar es obligatorio.', 422);
        $out['contrato'] = nt_contrato($d['contrato'] ?? null);
        if (!$out['contrato']) throw new ErrorApi('Seleccione el contrato: CAPITA, EVENTO o EVENTO SAS.', 422);
    }
    $precio = trim(str_replace(['$', ' '], '', (string) ($d['precio_venta'] ?? '')));
    if ($precio === '') $out['precio_venta'] = null;
    else {
        if (preg_match('/^\d{1,3}(\.\d{3})+$/', $precio)) $precio = str_replace('.', '', $precio); // 18.860
        $precio = str_replace(',', '.', $precio);
        if (!is_numeric($precio) || (float) $precio < 0) throw new ErrorApi('El precio de venta debe ser un número positivo.', 422);
        $out['precio_venta'] = round((float) $precio, 2);
    }
    $out['tipo_producto'] = in_array((string) ($d['tipo_producto'] ?? ''), ['714', '799'], true) ? (string) $d['tipo_producto']
        : nt_tipo_producto((string) ($out['cum_facturar'] ?? $d['cum_facturar'] ?? ''));
    $iva = (float) str_replace(',', '.', (string) ($d['iva'] ?? '0'));
    if ($iva > 1) $iva /= 100; // 19 -> 0.19
    if (!in_array(round($iva, 4), [0.0, 0.19], true)) throw new ErrorApi('El IVA debe ser 0 % o 19 %.', 422);
    $out['iva'] = round($iva, 4);
    if (!$out['cod_mutual']) throw new ErrorApi('El COD MUTUAL es obligatorio.', 422);
    return $out;
}

/** Crea o edita una fila de la Maestra Tarifaria (solo Administrador). El CUM a facturar y el CONTRATO no se editan: son la llave. */
/**
 * Activar / inactivar de forma masiva (pedido del usuario, 2026-10-16): por las filas seleccionadas o por todas las filas que
 * cumplen los filtros de la tabla. Es por PLU + CONTRATO (pedido del usuario, 2026-10-26): cada fila es «id de la línea|PLU».
 * En una línea con varios PLU se cambia solo ese PLU; la línea queda activa mientras tenga al menos un PLU activo. En una línea
 * con un solo PLU (o sin PLU) se cambia la línea. Cada cambio queda en auditoría (NT_EDITAR: activo antes / después, PLU y motivo).
 */
function nt_activar_masivo(PDO $pdo, array $claves, ?array $filtros, bool $activar, string $motivo, int $usuarioId): array {
    $motivo = trim($motivo);
    if ($motivo === '' || mb_strlen($motivo) > 300) throw new ErrorApi('Escriba el motivo (máximo 300 caracteres).', 422);
    $calc = nt_calcular($pdo, true)['filas'];
    if ($filtros !== null) $claves = array_map(fn($f) => $f['id'] . '|' . ($f['plu'] ?? ''), _nt_filtrar(nt_filas_por_plu($calc), $filtros));
    $porLinea = []; // id => [PLU => true] ('' = la línea completa)
    foreach ($claves as $c) {
        [$id, $plu] = array_pad(explode('|', (string) $c, 2), 2, '');
        if ((int) $id > 0) $porLinea[(int) $id][_u($plu)] = true;
    }
    if (!$porLinea) throw new ErrorApi('No hay líneas seleccionadas.', 422);
    if (array_sum(array_map('count', $porLinea)) > 20000) throw new ErrorApi('Son demasiadas líneas para una sola operación.', 422);
    $plusDe = []; // estado en la Maestra Tarifaria de los PLU de cada línea
    $pendDe = []; $pluCum = []; $cumDe = []; // PLU por definir de cada línea y, por CUM y PLU, las líneas que tienen ese PLU
    foreach ($calc as $f) {
        $plusDe[(int) $f['id']] = $f['_plus_mt'] ?? []; $pendDe[(int) $f['id']] = $f['_plus_pend'] ?? [];
        $cumDe[(int) $f['id']] = _u($f['cum_facturar']);
        foreach (array_keys($f['_plus_mt'] ?? []) as $p) $pluCum[_u((string) $p)][] = (int) $f['id']; // todas las líneas que tienen el PLU
    }
    // La inactivación es por PLU (aclaración del usuario, 2026-11-05): el PLU se activa / inactiva en TODAS las líneas que lo tienen
    // (todos sus contratos y, por lo tanto, todos sus subplanes de MEDICAR)
    foreach ($porLinea as $id => $elegidos) foreach (array_keys($elegidos) as $p) {
        if ((string) $p === '') continue;
        foreach ($pluCum[(string) $p] ?? [] as $otra) $porLinea[$otra][(string) $p] = true;
    }
    $nuevo = $activar ? 1 : 0;
    $cambiadas = 0; $sinCambio = 0;
    $sel = $pdo->prepare('SELECT id, activo FROM nt_item WHERE id = ?');
    $upd = $pdo->prepare('UPDATE nt_item SET activo = ?, actualizado_en = ?, actualizado_por = ? WHERE id = ?');
    $updPlu = $pdo->prepare('UPDATE nt_item_plu SET activo = ?, actualizado_por = ?, actualizado_en = ? WHERE nt_item_id = ? AND UPPER(plu) = ?');
    foreach ($porLinea as $id => $elegidos) {
        $sel->execute([$id]); $a = $sel->fetch();
        if (!$a) continue;
        $mt = $plusDe[$id] ?? [];
        $plus = array_map('strval', array_keys($mt));
        if (count($plus) <= 1 || isset($elegidos[''])) { // la línea completa (sin PLU o con uno solo): la línea y su PLU
            if ((int) $a['activo'] === $nuevo && !array_filter($mt, fn($v) => $v !== $nuevo)) { $sinCambio++; continue; }
            if ((int) $a['activo'] !== $nuevo) $upd->execute([$nuevo, ahora(), $usuarioId, $id]);
            foreach ($mt as $p => $v) if ($v !== $nuevo) $updPlu->execute([$nuevo, $usuarioId, ahora(), $id, (string) $p]);
            auditar($usuarioId, 'NT_EDITAR', 'nt_item', $id, ['activo' => (int) $a['activo']], ['activo' => $nuevo, 'motivo' => $motivo, 'masivo' => true]);
            $cambiadas++;
            continue;
        }
        // por PLU: estado actual de cada PLU, cambio de los elegidos; la línea queda activa si le queda algún PLU activo
        $estado = [];
        foreach ($plus as $p) $estado[$p] = (int) $a['activo'] === 1 && ($mt[$p] ?? 0) === 1;
        $antes = $estado;
        foreach (array_keys($elegidos) as $p) if (isset($estado[$p])) $estado[$p] = (bool) $nuevo;
        $pendL = array_fill_keys($pendDe[$id] ?? [], true);
        $cambio = array_keys(array_filter($estado, fn($v, $p) => $v !== $antes[$p] || (isset($elegidos[$p]) && isset($pendL[$p])), ARRAY_FILTER_USE_BOTH));
        $sinCambio += count(array_intersect_key($elegidos, $estado)) - count($cambio);
        if (!$cambio) continue;
        foreach ($cambio as $p) $updPlu->execute([(int) $estado[$p], $usuarioId, ahora(), $id, (string) $p]); // queda definido por el usuario
        $lineaActiva = in_array(true, $estado, true) ? 1 : 0;
        if ($lineaActiva !== (int) $a['activo']) $upd->execute([$lineaActiva, ahora(), $usuarioId, $id]);
        foreach ($estado as $p => $v) if ((int) $v !== ($mt[$p] ?? -1)) $updPlu->execute([(int) $v, $usuarioId, ahora(), $id, (string) $p]);
        foreach ($cambio as $p) {
            auditar($usuarioId, 'NT_EDITAR', 'nt_item', $id, ['activo' => (int) $antes[$p], 'plu' => $p],
                ['activo' => $nuevo, 'plu' => $p, 'linea_activa' => $lineaActiva, 'motivo' => $motivo, 'masivo' => true]);
            $cambiadas++;
        }
    }
    nt_calcular($pdo, true); // recalcula con el nuevo estado
    return ['cambiadas' => $cambiadas, 'sin_cambio' => $sinCambio];
}

function nt_guardar(PDO $pdo, array $d, int $usuarioId): array {
    $id = (int) ($d['id'] ?? 0);
    // Nuevo producto desde el formulario: una línea por contrato, cada una con su precio
    $lineas = $id === 0 && is_array($d['lineas'] ?? null) && $d['lineas'] ? array_values($d['lineas']) : null;
    if ($lineas) $d['contrato'] = $lineas[0]['contrato'] ?? null;
    $datos = _nt_normalizar($d, $id === 0);
    if ($id) {
        $st = $pdo->prepare('SELECT * FROM nt_item WHERE id = ?');
        $st->execute([$id]);
        $antes = $st->fetch();
        if (!$antes) throw new ErrorApi('Fila de la Maestra Tarifaria no encontrada.', 404);
        $datos['activo'] = !empty($d['activo']) ? 1 : 0;
        if ((string) $antes['precio_venta'] !== (string) $datos['precio_venta'] && $datos['precio_venta'] !== null
            && abs((float) $antes['precio_venta'] - (float) $datos['precio_venta']) > 0.001) $datos['origen_precio'] = 'MANUAL';
        // Vista de edición (pedido del usuario, 2026-11-05): COD AUF no se edita; DESCRIPCION y COD GEN SIESA salen del catálogo
        // COD GEN (moléculas) por el COD AUF (si el COD AUF no está en el catálogo, se conservan)
        $datos['cod_auf'] = $antes['cod_auf'];
        $st = $pdo->prepare('SELECT descripcion_general, codigo_medicar FROM cat_molecula WHERE UPPER(cod_gen_audifarma) = ?');
        $st->execute([_u((string) $antes['cod_auf'])]);
        $mol = $st->fetch();
        $datos['descripcion'] = $mol ? (mb_strtoupper(trim(preg_replace('/\s+/u', ' ', (string) $mol['descripcion_general']))) ?: $antes['descripcion']) : $antes['descripcion'];
        $datos['cod_gen_siesa'] = $mol ? (trim((string) $mol['codigo_medicar']) ?: $antes['cod_gen_siesa']) : $antes['cod_gen_siesa'];
        // datos INVIMA / RIPS propios de la Maestra Tarifaria (vacío = el de SIESA / INVIMA)
        if (is_array($d['datos_mt'] ?? null)) foreach (NT_DATOS_MT as $k => [$col, $largo]) {
            if (!array_key_exists($k, $d['datos_mt'])) continue;
            $v = trim(preg_replace('/\s+/u', ' ', (string) $d['datos_mt'][$k]));
            $datos[$col] = $v === '' ? null : mb_substr(mb_strtoupper($v), 0, $largo);
        }
        // CUM a facturar (la llave): se cambia en todas las líneas del CUM (todos los contratos), si el nuevo no existe ya
        $viejoCf = (string) $antes['cum_facturar'];
        $nuevoCf = _u(trim(preg_replace('/\s+/u', ' ', (string) ($d['cum_facturar'] ?? ''))));
        $renombrar = $nuevoCf !== '' && $nuevoCf !== _u($viejoCf);
        if ($renombrar) {
            if (mb_strlen($nuevoCf) > 60) throw new ErrorApi('El CUM a facturar admite máximo 60 caracteres.', 422);
            $st = $pdo->prepare('SELECT contrato FROM nt_item WHERE UPPER(cum_facturar) = ?');
            $st->execute([$nuevoCf]);
            if ($ya = $st->fetchAll(PDO::FETCH_COLUMN))
                throw new ErrorApi("El CUM a facturar $nuevoCf ya existe en la Maestra Tarifaria (" . implode(', ', array_map('nt_contrato_vista', $ya)) . '): no se puede cambiar a ese.', 409);
            $datos['cum_facturar'] = $nuevoCf;
        }
        $difiere = fn(array $fila, array $nuevos) => array_filter($nuevos, fn($v, $k) => (string) $fila[$k] !== (string) $v
            && !(is_numeric($fila[$k]) && is_numeric($v) && abs((float) $fila[$k] - (float) $v) < 0.001), ARRAY_FILTER_USE_BOTH);
        $cambios = $difiere($antes, $datos);
        if (!$cambios) return ['id' => $id, 'cambios' => 0];
        if (array_key_exists('precio_venta', $cambios) && $antes['precio1_aprobado_en']) $cambios += ['precio1_aprobado_por' => null, 'precio1_aprobado_en' => null];
        $actualizar = function (int $idF, array $c) use ($pdo, $usuarioId) {
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($c)));
            $pdo->prepare("UPDATE nt_item SET $sets, actualizado_en = ?, actualizado_por = ? WHERE id = ?")->execute([...array_values($c), ahora(), $usuarioId, $idF]);
        };
        $actualizar($id, $cambios);
        // la línea completa: sus PLU conservan su estado en la Maestra Tarifaria (al reactivarla sin ningún PLU activo se toman los de SIESA)
        auditar($usuarioId, 'NT_EDITAR', 'nt_item', $id, array_intersect_key($antes, $cambios), $cambios);
        $total = count($cambios);
        // los datos del producto (CUM a facturar, CUM, registro, ATC, RIPS) son del CUM: se aplican en todas sus líneas (todos los contratos)
        $delCum = array_intersect_key($datos, array_flip(['cum', 'cum_facturar', ...array_column(NT_DATOS_MT, 0)]));
        $st = $pdo->prepare('SELECT * FROM nt_item WHERE cum_facturar = ? AND id <> ?');
        $st->execute([$viejoCf, $id]);
        $ids = [$id];
        foreach ($st->fetchAll() as $o) {
            $ids[] = (int) $o['id'];
            $c2 = $difiere($o, array_intersect_key($delCum, $cambios));
            if (!$c2) continue;
            $actualizar((int) $o['id'], $c2);
            auditar($usuarioId, 'NT_EDITAR', 'nt_item', (int) $o['id'], array_intersect_key($o, $c2), $c2 + ['motivo' => "Desde la línea {$antes['contrato']} del CUM $viejoCf"]);
            $total += count($c2);
        }
        if ($renombrar) {
            // los PLU del CUM viejo ya no son de la línea: se toman los del nuevo CUM en SIESA; la aprobación de varios PLU pasa al nuevo
            $pdo->prepare('DELETE FROM nt_item_plu WHERE nt_item_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')->execute($ids);
            $pdo->prepare('UPDATE cor_aprobacion SET cum_facturar = ? WHERE cum_facturar = ?')->execute([$nuevoCf, $viejoCf]);
        }
        return ['id' => $id, 'cambios' => $total, 'lineas' => count($ids), 'renombrado' => $renombrar ? $nuevoCf : null];
    }
    // Nuevo producto: el COD AUF debe existir en COD GEN (moléculas); lo que no se digitó se completa solo.
    if (!$datos['cod_auf']) throw new ErrorApi('El COD AUF es obligatorio.', 422);
    $sug = nt_sugerencia($pdo, $datos['cod_auf'], $datos['cum_facturar']);
    if (!$sug['molecula']) throw new ErrorApi("El COD AUF {$datos['cod_auf']} no existe en el catálogo COD GEN (moléculas): agréguelo primero al catálogo.", 422);
    $datos['descripcion'] ??= mb_strtoupper((string) $sug['molecula']['descripcion_general']);
    $datos['cod_gen_siesa'] ??= $sug['molecula']['codigo_medicar'] ?: null;
    $lineas ??= [['contrato' => $datos['contrato'], 'precio_venta' => $datos['precio_venta'], 'calculo' => $d['calculo'] ?? null]];
    $existe = $pdo->prepare('SELECT id FROM nt_item WHERE cum_facturar = ? AND contrato = ?');
    $creadas = []; $omitidas = [];
    foreach ($lineas as $l) {
        $k = nt_contrato($l['contrato'] ?? null);
        if (!$k) throw new ErrorApi('Contrato no válido en una de las líneas.', 422);
        $existe->execute([$datos['cum_facturar'], $k]);
        if ($existe->fetchColumn()) { $omitidas[] = $k; continue; } // ya existe: no se duplica
        $pv = _nt_normalizar(['precio_venta' => $l['precio_venta'] ?? null, 'cod_mutual' => 'X', 'iva' => $datos['iva']], false)['precio_venta'];
        // origen del precio: calculadora (hoja DOC CRIS), listado cápita, contrato o digitado
        $calc = is_array($l['calculo'] ?? null) ? $l['calculo'] : null;
        $origen = $calc ? mb_substr((string) ($calc['origen'] ?? 'MANUAL'), 0, 20) : ($pv !== null ? 'MANUAL' : null);
        $fila = ['contrato' => $k, 'precio_venta' => $pv] + $datos;
        $nuevo = insertar($pdo, 'nt_item', $fila + ['fecha' => date('Y-m-d'), 'origen_precio' => $origen, 'activo' => 1, 'creado_en' => ahora(), 'creado_por' => $usuarioId]);
        if ($calc && $pv !== null && ($calc['origen'] ?? '') === 'CALCULADORA') {
            insertar($pdo, 'nt_precio_calculo', ['nt_item_id' => $nuevo, 'precio_compra' => is_numeric($calc['precio_compra'] ?? null) ? (float) $calc['precio_compra'] : null,
                'porcentaje' => is_numeric($calc['porcentaje'] ?? null) ? (float) $calc['porcentaje'] : null, 'precio_regulado' => $sug['regulado'],
                'precio_capita' => $sug['tarifa_capita'], 'precio_resultado' => $pv, 'regla' => mb_substr((string) ($calc['regla'] ?? ''), 0, 200),
                'usuario_id' => $usuarioId, 'creado_en' => ahora()]);
        }
        auditar($usuarioId, 'NT_CREAR', 'nt_item', $nuevo, null, $fila + ($calc ? ['calculo' => $calc] : []));
        $creadas[] = $k;
    }
    if (!$creadas) throw new ErrorApi("El CUM a facturar {$datos['cum_facturar']} ya tiene línea en: " . implode(', ', $omitidas) . '.', 409);
    return ['id' => $nuevo, 'cambios' => count($creadas), 'creadas' => $creadas, 'omitidas' => $omitidas, 'lineas_repetidas' => nt_repetir_lineas($pdo, $usuarioId)];
}

/**
 * Datos para el formulario «Nuevo» de la Maestra Tarifaria: lo que se llena solo a partir del COD AUF y del CUM a facturar.
 *  - COD GEN (moléculas) por COD AUF: DESCRIPCION y COD GEN SIESA (= Cód. Medicar). Si no existe, no se puede crear.
 *  - Maestra Mutual Ser por CUM: ID MUTUAL -> COD MUTUAL. COD MUTUAL FINAL = COD AUF si ese ID MUTUAL ya lo usa
 *    otro COD AUF en la Maestra Tarifaria; si no, el mismo ID MUTUAL (decisión del usuario, 2026-10-03).
 *  - Maestro SIESA por CUM: IVA (grupo impositivo). Tipo 714 / 799 por el CUM a facturar.
 *  - Precios (hoja DOC CRIS, sin el precio Audifarma): tarifa del Listado Cápita, valor del Contrato Evento,
 *    precio regulado y % de la calculadora (parámetro NT_PORCENTAJE_PRECIO).
 */
function nt_sugerencia(PDO $pdo, string $codAuf, string $cumFacturar, string $codMutual = ''): array {
    $e = _u($codAuf); $cf = _u($cumFacturar);
    $r = ['cod_auf' => $e, 'cum_facturar' => $cf, 'molecula' => null, 'cod_mutual' => null, 'ids_cum' => [], 'otros_cod_auf' => [],
          'iva' => null, 'tipo_producto' => $cf !== '' ? nt_tipo_producto($cf) : null,
          'contrato_sugerido' => 'EVENTO', 'contrato_evento' => [], 'tarifa_capita' => null, 'regulado' => null,
          'porcentaje' => (float) parametro('NT_PORCENTAJE_PRECIO', '20'), 'lineas_existentes' => [], 'precio_nt_cod_auf' => null, 'en_evento_sas' => false];
    if ($e !== '') {
        $st = $pdo->prepare('SELECT cod_gen_audifarma, descripcion_general, codigo_medicar, pos_no_pos, activo FROM cat_molecula WHERE UPPER(cod_gen_audifarma) = ?');
        $st->execute([$e]);
        $r['molecula'] = $st->fetch() ?: null;
        $st = $pdo->prepare('SELECT tarifa_pactada FROM ref_capita WHERE carga_id = ? AND UPPER(codigo_cup) = ? ORDER BY id');
        $st->execute([_carga_vigente($pdo, 'LISTADO_CAPITA'), $e]);
        $t = $st->fetchColumn(); $r['tarifa_capita'] = $t === false ? null : (float) $t;
        $st = $pdo->prepare('SELECT precio_regulado FROM ref_precio_regulado WHERE carga_id = ? AND UPPER(cod_gen) = ? ORDER BY id');
        $st->execute([_carga_vigente($pdo, 'PRECIOS_REGULADOS'), $e]);
        $g = $st->fetchColumn(); $r['regulado'] = $g === false ? null : (float) $g;
        $st = $pdo->prepare('SELECT MAX(precio_venta) FROM nt_item WHERE UPPER(cod_auf) = ? AND activo = 1');
        $st->execute([$e]);
        $pn = $st->fetchColumn(); $r['precio_nt_cod_auf'] = $pn === null || $pn === false ? null : (float) $pn;
        if ($r['tarifa_capita'] !== null) $r['contrato_sugerido'] = 'CAPITA';
    }
    if ($cf !== '') {
        $st = $pdo->prepare('SELECT UPPER(id_mutual) FROM ref_medicamentos_mutual WHERE carga_id = ? AND cum = ? AND id_mutual IS NOT NULL GROUP BY UPPER(id_mutual) ORDER BY MIN(id)');
        $st->execute([_carga_vigente($pdo, 'MEDICAMENTOS_MUTUAL'), $cf]);
        $r['ids_cum'] = $st->fetchAll(PDO::FETCH_COLUMN);
        // insumo sin CUM: el código del insumo es su propio ID
        $r['cod_mutual'] = count($r['ids_cum']) === 1 ? $r['ids_cum'][0] : (!str_contains($cf, '-') && $r['ids_cum'] === [] ? $cf : null);
        $st = $pdo->prepare('SELECT s.grupo_impositivo, g.iva_medicar FROM ref_siesa_item s LEFT JOIN cat_grupo_impositivo g ON g.codigo = s.grupo_impositivo
                              WHERE s.carga_id = ? AND s.cum = ? ORDER BY CASE WHEN UPPER(s.estado) = \'ACTIVO\' THEN 0 ELSE 1 END LIMIT 1');
        $st->execute([_carga_vigente($pdo, 'MAESTRO_SIESA'), $cf]);
        $si = $st->fetch(); $r['iva'] = $si && $si['iva_medicar'] !== null ? ((float) $si['iva_medicar']) / 100 : null;
        $st = $pdo->prepare('SELECT 1 FROM ref_evento_sas WHERE carga_id = ? AND (UPPER(cum) = ? OR (cum IS NULL AND UPPER(cod_audifarma) = ?)) ORDER BY id LIMIT 1');
        $st->execute([_carga_vigente($pdo, 'LISTADO_EVENTO_SAS'), $cf, $e]);
        $r['en_evento_sas'] = (bool) $st->fetchColumn();
        if ($r['en_evento_sas'] && $r['contrato_sugerido'] !== 'CAPITA') $r['contrato_sugerido'] = 'EVENTO SAS';
        $st = $pdo->prepare('SELECT contrato FROM nt_item WHERE cum_facturar = ?');
        $st->execute([$cf]);
        $r['lineas_existentes'] = $st->fetchAll(PDO::FETCH_COLUMN);
    }
    // El CUM no tiene ID MUTUAL (ni en la Maestra Mutual) pero el COD AUF ya está en la Maestra Tarifaria: se usa el ID de esa línea
    // (prefiere la línea EVENTO). Decisión del usuario (2026-10-11).
    if ($r['cod_mutual'] === null && $e !== '' && !$r['ids_cum']) {
        $st = $pdo->prepare("SELECT cod_mutual FROM nt_item WHERE activo = 1 AND UPPER(cod_auf) = ? AND cod_mutual IS NOT NULL AND cod_mutual <> ''
                             ORDER BY CASE contrato WHEN 'EVENTO' THEN 0 WHEN 'EVENTO SAS' THEN 1 ELSE 2 END, id LIMIT 1");
        $st->execute([$e]);
        if ($id = $st->fetchColumn()) { $r['cod_mutual'] = _u($id); $r['cod_mutual_origen'] = 'NT por COD AUF'; }
    }
    // COD MUTUAL digitado a mano (p.ej. el CUM no está en la Maestra Mutual): con él se busca el contrato
    if (_u($codMutual) !== '') $r['cod_mutual'] = _u($codMutual);
    if ($r['cod_mutual'] !== null) {
        // otros COD AUF que usan el mismo ID MUTUAL (informativo)
        $st = $pdo->prepare('SELECT DISTINCT cod_auf FROM nt_item WHERE cod_mutual = ? AND activo = 1 AND cod_auf IS NOT NULL AND cod_auf <> ?');
        $st->execute([$r['cod_mutual'], $e]);
        $r['otros_cod_auf'] = $st->fetchAll(PDO::FETCH_COLUMN);
        $st = $pdo->prepare('SELECT cod_servicio, valor_unitario, estado FROM ref_contrato_evento WHERE carga_id = ? AND UPPER(id_mutual) = ? ORDER BY id');
        $st->execute([_carga_vigente($pdo, 'CONTRATO_EVENTO_22382'), $r['cod_mutual']]);
        foreach ($st as $c) $r['contrato_evento'][(string) $c['cod_servicio']] = ['valor' => (float) $c['valor_unitario'], 'estado' => $c['estado']];
    }
    // Contratos donde se crea el producto nuevo (decisión del usuario, 2026-10-03): siempre EVENTO (si el ID MUTUAL no
    // está en el Contrato Evento queda con error NT01), más CAPITA si está en el Listado Cápita y EVENTO SAS si
    // está en el Listado EVENTO SAS.
    $r['contratos'] = array_values(array_filter([$r['tarifa_capita'] !== null ? 'CAPITA' : null, $r['en_evento_sas'] ? 'EVENTO SAS' : null, 'EVENTO']));
    return $r;
}


/**
 * Importación desde Excel (hoja EVENTO, la del Excel actual o la exportada por el aplicativo).
 * Vista previa: el archivo se lee al área de importación DENTRO de una transacción que se deshace;
 * al aplicar se repite la lectura, se aplican los cambios y se guarda el archivo con trazabilidad.
 * Celda vacía = no cambia. Las filas de la Maestra Tarifaria que no vienen en el archivo no se borran (se listan).
 * Llave: CUM a facturar + CONTRATO. CONTRATO vacío: el de la única línea que ya tenga ese CUM o, si no, CAPITA
 * cuando el COD AUF está en el Listado Cápita y EVENTO si no. Repetida en el archivo: se conserva la más antigua (FECHA).
 * Al aplicar se crean las líneas que falten en los demás contratos (nt_repetir_lineas).
 */
function nt_importar(PDO $pdo, string $ruta, string $nombre, int $usuarioId, bool $aplicar): array {
    $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xlsm'], true)) throw new ErrorApi('Use un archivo de Excel (.xlsx).', 422);
    $hash = hash_file('sha256', $ruta);
    $pdo->beginTransaction();
    try {
        $cargaId = insertar($pdo, 'ref_carga', ['tipo_base' => 'NOTA_TECNICA', 'origen' => $nombre, 'hash_sha256' => $hash, 'filas' => 0,
            'estado' => 'PREVISUALIZADA', 'vigente' => 0, 'version' => 0, 'tamano_bytes' => filesize($ruta), 'detalle' => '{}',
            'usuario_id' => $usuarioId, 'creado_en' => ahora()]);
        $lectura = lector_procesar($pdo, 'NOTA_TECNICA', $ruta, $ext, $cargaId, true);

        $actuales = []; $contratosDe = [];
        foreach ($pdo->query('SELECT * FROM nt_item') as $f) {
            $actuales[_u($f['cum_facturar']) . '|' . $f['contrato']] = $f;
            $contratosDe[_u($f['cum_facturar'])][] = $f['contrato'];
        }
        $st = $pdo->prepare('SELECT UPPER(codigo_cup) FROM ref_capita WHERE carga_id = ? ORDER BY id');
        $st->execute([_carga_vigente($pdo, 'LISTADO_CAPITA')]);
        $enCapita = array_fill_keys($st->fetchAll(PDO::FETCH_COLUMN), true);
        $archivo = [];
        $repetidos = [];
        $st = $pdo->prepare('SELECT ' . implode(', ', NT_CAMPOS) . ', precio_m_archivo, precio_final_archivo, subplan_archivo FROM ref_nt_item WHERE carga_id = ? ORDER BY COALESCE(fecha, \'9999\'), id');
        $st->execute([$cargaId]);
        foreach ($st as $f) {
            $cf = _u($f['cum_facturar']);
            $f['contrato'] = nt_contrato($f['contrato']) ?? (count($contratosDe[$cf] ?? []) === 1 ? $contratosDe[$cf][0]
                : (isset($enCapita[_u($f['cod_auf'])]) ? 'CAPITA' : 'EVENTO'));
            // CONTRATO EVENTO + SUBPLAN «EVENTO SAS SIN AUTORIZACION» = la línea SAS (el Excel exportado ya no dice «EVENTO SAS» en CONTRATO)
            if ($f['contrato'] === 'EVENTO' && str_starts_with(_u($f['subplan_archivo'] ?? ''), 'EVENTO SAS')) $f['contrato'] = 'EVENTO SAS';
            unset($f['subplan_archivo']);
            $k = "$cf|{$f['contrato']}";
            if (isset($archivo[$k])) {
                if ($f == $archivo[$k]) continue; // la misma línea en otra fila de PLU (el Excel trae un PLU por fila)
                $repetidos[] = "$cf {$f['contrato']} (fecha {$f['fecha']}): se conserva la fila del {$archivo[$k]['fecha']}"; continue;
            }
            $archivo[$k] = $f;
        }

        $nuevos = []; $cambios = []; $sinCambios = 0; $calculadas = [];
        $editables = array_diff(NT_CAMPOS, ['cum_facturar', 'contrato']);
        // Columnas calculadas editadas en el archivo (¿PRECIO CORRECTO- M?, PRECIO FINAL + IVA): no se cargan, se avisa.
        $calc = [];
        foreach (nt_calcular($pdo)['filas'] as $c) $calc[_u($c['cum_facturar']) . '|' . $c['contrato']] = $c;
        foreach ($archivo as $k => $f) {
            $c = $calc[$k] ?? null;
            $pv = $f['precio_venta'] ?? $c['precio_venta'] ?? null; $iva = $f['iva'] ?? $c['iva'] ?? 0;
            if ($f['precio_final_archivo'] !== null && $pv !== null && abs((float) $f['precio_final_archivo'] - round((float) $pv * (1 + (float) $iva))) >= 1) {
                $calculadas[] = ['cum_facturar' => explode('|', $k)[0], 'contrato' => explode('|', $k)[1], 'columna' => 'PRECIO FINAL + IVA',
                                 'archivo' => (float) $f['precio_final_archivo'], 'calculado' => round((float) $pv * (1 + (float) $iva))];
            }
            if ($c && $f['precio_m_archivo'] !== null && $c['precio_maximo'] !== null && abs((float) $f['precio_m_archivo'] - (float) $c['precio_maximo']) >= 1) {
                $calculadas[] = ['cum_facturar' => explode('|', $k)[0], 'contrato' => explode('|', $k)[1], 'columna' => '¿PRECIO CORRECTO- M?',
                                 'archivo' => (float) $f['precio_m_archivo'], 'calculado' => (float) $c['precio_maximo']];
            }
            unset($archivo[$k]['precio_m_archivo'], $archivo[$k]['precio_final_archivo']);
        }
        foreach ($archivo as $k => $f) {
            $f['cum_facturar'] = explode('|', $k)[0];
            foreach (['cum', 'cod_auf', 'tipo_producto', 'descripcion', 'cod_gen_siesa', 'nuevo_cod_mutual', 'cod_mutual'] as $c) {
                if ($f[$c] !== null) $f[$c] = _u($f[$c]);
            }
            if (!in_array($f['tipo_producto'], ['714', '799'], true)) $f['tipo_producto'] = nt_tipo_producto($f['cum_facturar']);
            if (!isset($actuales[$k])) { $nuevos[] = $f; continue; }
            $a = $actuales[$k]; $dif = [];
            foreach ($editables as $c) {
                if ($f[$c] === null || $f[$c] === '') continue; // celda vacía: no cambia
                if ($c === 'precio_venta' && ($calc[$k]['fuente_precio'] ?? 'DIGITADO') !== 'DIGITADO') continue; // 7F: CAPITA sale del Listado Cápita
                $igual = is_numeric($f[$c]) && is_numeric($a[$c]) ? abs((float) $f[$c] - (float) $a[$c]) < 0.005 : _u((string) $f[$c]) === _u((string) $a[$c]);
                if (!$igual) $dif[$c] = ['antes' => $a[$c], 'despues' => $f[$c]];
            }
            if ($dif) $cambios[] = ['id' => (int) $a['id'], 'cum_facturar' => $f['cum_facturar'], 'contrato' => $f['contrato'], 'campos' => $dif];
            else $sinCambios++;
        }
        $noEnArchivo = array_values(array_map(fn($a) => "{$a['cum_facturar']} ({$a['contrato']})", array_filter($actuales, fn($a, $k) => !isset($archivo[$k]) && (int) $a['activo'], ARRAY_FILTER_USE_BOTH)));

        $resumen = ['filas_archivo' => $lectura['filas'], 'omitidas' => $lectura['omitidas'], 'repetidas' => count($repetidos),
                    'nuevos' => count($nuevos), 'actualizar' => count($cambios), 'sin_cambios' => $sinCambios, 'no_en_archivo' => count($noEnArchivo),
                    'calculadas_editadas' => count($calculadas),
                    'hoja' => $lectura['hoja_leida'], 'fila_encabezado' => $lectura['fila_encabezado']];
        $respuesta = ['resumen' => $resumen, 'aplicado' => false,
                      'cambios' => array_slice($cambios, 0, 500), 'nuevos' => array_slice(array_map(fn($n) => array_intersect_key($n, array_flip(['cum_facturar', 'contrato', 'cod_mutual', 'descripcion', 'precio_venta'])), $nuevos), 0, 500),
                      'repetidos' => array_slice($repetidos, 0, 100), 'omitidas' => array_slice($lectura['detalle_omitidas'], 0, 100),
                      'no_en_archivo' => array_slice($noEnArchivo, 0, 500), 'calculadas' => array_slice($calculadas, 0, 500)];
        if (!$aplicar) { $pdo->rollBack(); return $respuesta; }

        // --- aplicar
        $primeraCarga = !$actuales;
        foreach ($nuevos as $f) {
            $id = insertar($pdo, 'nt_item', array_intersect_key($f, array_flip(NT_CAMPOS)) + [
                'origen_precio' => $f['precio_venta'] !== null ? 'IMPORTADO' : null, 'activo' => 1, 'creado_en' => ahora(), 'creado_por' => $usuarioId]);
            if (!$primeraCarga) auditar($usuarioId, 'NT_CREAR', 'nt_item', $id, null, $f + ['origen' => $nombre]);
        }
        foreach ($cambios as $c) {
            $valores = array_map(fn($x) => $x['despues'], $c['campos']);
            if (isset($valores['precio_venta'])) $valores += ['origen_precio' => 'IMPORTADO', 'precio1_aprobado_por' => null, 'precio1_aprobado_en' => null];
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($valores)));
            $pdo->prepare("UPDATE nt_item SET $sets, actualizado_en = ?, actualizado_por = ? WHERE id = ?")->execute([...array_values($valores), ahora(), $usuarioId, $c['id']]);
            auditar($usuarioId, 'NT_EDITAR', 'nt_item', $c['id'], array_map(fn($x) => $x['antes'], $c['campos']), $valores + ['origen' => $nombre]);
        }
        // archivo con trazabilidad; el área de importación se vacía
        $st = $pdo->prepare("SELECT COALESCE(MAX(version), 0) FROM ref_carga WHERE tipo_base = 'NOTA_TECNICA'");
        $st->execute();
        $version = (int) $st->fetchColumn() + 1;
        $dir = __DIR__ . '/../data/bases/NOTA_TECNICA';
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new RuntimeException("No se pudo crear $dir");
        $destino = sprintf('%s/v%03d_%s.%s', $dir, $version, date('Ymd_His'), $ext);
        if (!copy($ruta, $destino)) throw new RuntimeException('No se pudo guardar el archivo.');
        $pdo->prepare('DELETE FROM ref_nt_item WHERE carga_id = ?')->execute([$cargaId]);
        $pdo->prepare("UPDATE ref_carga SET estado = 'APLICADA', version = ?, filas = ?, omitidas = ?, archivo = ?, leido_en = ?, detalle = ? WHERE id = ?")
            ->execute([$version, $lectura['filas'], $lectura['omitidas'], 'data/bases/NOTA_TECNICA/' . basename($destino), ahora(),
                       json_encode(['resumen' => $resumen, 'repetidos' => $repetidos], JSON_UNESCAPED_UNICODE), $cargaId]);
        auditar($usuarioId, 'NT_IMPORTAR', 'ref_carga', $cargaId, null, ['archivo' => $nombre, 'hash' => $hash, 'version' => $version] + $resumen);
        $pdo->commit();
        $respuesta['aplicado'] = true;
        $respuesta['version'] = $version;
        $respuesta['lineas_repetidas'] = nt_repetir_lineas($pdo, $usuarioId);
        return $respuesta;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (isset($destino) && is_file($destino)) @unlink($destino);
        throw $e;
    }
}

/** Exporta la Maestra Tarifaria con la estructura de la hoja EVENTO 2025 (se puede volver a importar). */
function nt_exportar(PDO $pdo, string $destino, array $filtros = [], bool $soloErrores = false, bool $conPrecios = true): string {
    $anio = parametro('NT_ANIO_PRECIO', '2026');
    // Mismas columnas y orden de la tabla de la pantalla (decisión del usuario, 2026-10-11) + ESTADO al final.
    // Se puede volver a importar (el lector acepta FECHA DE CREACION y no exige COD MUTUAL FINAL).
    $enc = ['FECHA DE CREACION', 'CONTRATO', 'SUBPLAN', 'CUM', 'CUM FACTURAR', 'COD AUF', 'TIPO PRODUCTO', 'DESCRIPCION', 'COD GEN SIESA',
            'PLU', 'DESCRIPCION COMPLETA', 'ATC', 'COD MUTUAL', "PRECIO DE VENTA $anio", 'IVA', 'PRECIO FINAL + IVA', '¿ESTA EN EL CONTRATO?',
            'PRECIO CONTRATO', '¿PRECIO CORRECTO - M?', 'PRECIO REGULADO', 'FORMA FARMACEUTICA RIPS', 'CONCENTRACION RIPS', 'UNIDAD MEDIDA RIPS',
            'CLASIFICACION DE ENTREGA', 'OBSERVACIONES', 'ESTADO'];
    if ($soloErrores) $enc[] = 'CÓMO CORREGIR';
    $accion = $pdo->query("SELECT codigo, accion_sugerida FROM proc_regla WHERE codigo LIKE 'NT%'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $num = fn($v) => $v === null || $v === '' ? null : (float) $v;
    $filas = (function () use ($pdo, $num, $filtros, $soloErrores, $accion, $conPrecios) {
        foreach (_nt_filtrar(nt_filas_por_plu(nt_calcular($pdo)['filas']), $filtros) as $f) {
            if (!$conPrecios) $f = nt_sin_precios($f);
            if (!(int) $f['activo'] || ($soloErrores && !$f['motivos'])) continue;
            yield [$f['fecha'], $f['contrato_vista'], $f['subplan'], $f['cum'], $f['cum_facturar'], $f['cod_auf'], $f['tipo_producto'], $f['descripcion'],
                   $f['cod_gen_siesa_calc'], $f['referencia_siesa'], $f['descripcion_siesa'], $f['atc'], $f['cod_mutual'],
                   $num($f['precio_venta']), $num($f['iva']), $num($f['precio_final']), $f['en_contrato'],
                   $f['precio_contrato'] === null ? ($f['contrato'] === 'CAPITA' ? 'NO ESTA EN EL LISTADO CAPITA' : 'NO ESTA EN EL CONTRATO') : $num($f['precio_contrato']),
                   $num($f['precio_maximo']), $num($f['precio_regulado']), $f['ffm_rips'], $f['umm_rips'], $f['upr_rips'], $f['clasificacion_entrega'],
                   implode(' · ', array_map(fn($m) => "{$m['codigo']}: {$m['texto']}", $f['motivos'])), $f['estado'],
                   ...($soloErrores ? [implode(' · ', array_unique(array_map(fn($m) => "{$m['codigo']}: " . ($accion[$m['codigo']] ?? ''), $f['motivos'])))] : [])];
        }
    })();
    if (!$conPrecios) { // sin PRECIO.VER: se quitan las columnas de precio
        $quitar = array_flip(array_keys(array_filter($enc, fn($h) => str_contains($h, 'PRECIO'))));
        $enc = array_values(array_diff_key($enc, $quitar));
        $filas = (function () use ($filas, $quitar) { foreach ($filas as $r) yield array_values(array_diff_key($r, $quitar)); })();
    }
    XlsxWriter::guardar($destino, [['nombre' => 'EVENTO 2025', 'encabezados' => $enc, 'filas' => $filas]]);
    return ($soloErrores ? 'MAESTRA TARIFARIA - ERRORES A CORREGIR' : 'MAESTRA TARIFARIA CONTRATO ' . $anio . ($filtros ? ' - FILTRADA' : '')) . ($conPrecios ? '' : ' - SIN PRECIOS') . ' - ' . date('Ymd') . '.xlsx';
}
