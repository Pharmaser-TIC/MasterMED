<?php
/**
 * Fase 5 — Solicitudes de creación y motor de validación.
 *
 * Una SOLICITUD (tabla proc_lote) agrupa los productos que se capturan y validan juntos; no es el lote de SIESA
 * (ese es PH001 para todos y no se valida). Cada producto (proc_lote_item) lleva las columnas que se digitan en la
 * hoja FORMATO del Excel «CREAR ITEMS»; lo demás se calcula desde los catálogos y las bases vigentes.
 *
 * Reglas aprobadas por el usuario (2026-10-03), ver PLAN_DE_TRABAJO.md (fase 5):
 *   ERROR: E01 obligatorios · E03 COD AUF no está en COD GEN · E04 laboratorio · E05 presentación · E06 código ya existe
 *          en SIESA · E07 ya creado en el aplicativo · E08 CUM activo en SIESA · E09 INVIMA (registro no vigente) ·
 *          E10 no está en la Maestra Tarifaria / no está en el contrato · E12 la línea de la Maestra Tarifaria tiene errores · E13 precio vacío o 1 ·
 *          E14 repetido en la solicitud · E15 subplán · E16 RIPS · E17 COD GEN en «ASIGNAR».
 *   (Molécula ya en Medicar: no es regla; el producto se crea y la molécula no va en el formato 1 de la fase 6.)
 *   ERROR E18: el CUM ya está creado en SIESA pero inactivo (activar el PLU) · ALERTA: A05 precio 1 aprobado · A07 base desactualizada ·
 *           A08 fecha / estado INVIMA distintos a INVIMA.
 * E03, E04, E05, E17 y E10 (no está en la Maestra Tarifaria) generan una APROBACIÓN del Administrador (proc_aprobacion).
 */
require_once __DIR__ . '/datos.php';
require_once __DIR__ . '/reglas.php';
require_once __DIR__ . '/nota_tecnica.php';
require_once __DIR__ . '/catalogos_admin.php';
require_once __DIR__ . '/xlsx_writer.php';

/** Columnas que se digitan (hoja FORMATO): campo => [encabezado del Excel, obligatorio]. */
const SOL_CAMPOS = [
    'codigo_generico_audifarma' => ['CODIGO GENERICO AUDIFARMA', true],
    'iva'                       => ['IVA', true],
    'cum'                       => ['CUM', true],
    'codigo_cum_auxiliar'       => ['CODIGO CUM AUXILIAR', true],
    'registro_sanitario'        => ['REGISTRO SANITARIO', true],
    'codigo_ium'                => ['CODIGO IUM', true],
    'nombre_comercial'          => ['NOMBRE COMERCIAL', true],
    'laboratorio'               => ['LABORATORIO', true],
    'codigo_atc'                => ['CODIGO ATC', true],
    'tipo_medicamento'          => ['TIPO DE MEDICAMENTO', true],
    'fecha_reg_invima'          => ['FECHA REG INVIMA', false], // obligatoria en insumos (como el Excel)
    'estado_invima'             => ['ESTADO INVIMA', true],
    'presentacion_1'            => ['PRESENTACION 1', true],
    'unidad_presentacion'       => ['UNIDAD DE PRESENTACION', true],
    'forma_farmaceutica_rips'   => ['FORMA FARMACEUTICA RIPS', true],
    'concentracion_rips'        => ['CONCENTRACION MEDICAMENTO RIPS', true],
    'unidad_medida_rips'        => ['UNIDAD MEDIDA RIPS', true],
];
/** Valores especiales del CUM / registro que se tratan como activos (como el Excel). */
const SOL_ESPECIALES = ['NO APLICA', 'VITAL NO DISPONIBLE', 'PREPARADO MAGISTRAL'];
/** Subplán principal -> línea de la Maestra Tarifaria (decisión P5-1). */
const SOL_CONTRATO_NT = ['CAPITA PBS' => 'CAPITA', 'EVENTO SAS SIN AUTORIZACION' => 'EVENTO SAS'];

// ------------------------------------------------------------------------------------------- solicitudes

/**
 * Productos por crear (decisión del usuario, 2026-10-03): no se arman solicitudes; todos los productos que se
 * capturan quedan en una sola lista abierta (internamente, la solicitud abierta) hasta que se crean en la fase 6.
 */
function sol_abierta(PDO $pdo, int $usuarioId): int {
    $id = $pdo->query("SELECT MIN(id) FROM proc_lote WHERE estado IN ('BORRADOR', 'VALIDADA')")->fetchColumn();
    return $id ? (int) $id : sol_crear($pdo, 'Productos por crear', $usuarioId);
}

function sol_crear(PDO $pdo, string $observacion, int $usuarioId): int {
    $id = insertar($pdo, 'proc_lote', ['estado' => 'BORRADOR', 'observacion' => mb_substr(trim($observacion), 0, 500) ?: null,
        'creado_por' => $usuarioId, 'creado_en' => ahora()]);
    auditar($usuarioId, 'SOLICITUD_CREAR', 'proc_lote', $id, null, ['observacion' => $observacion]);
    return $id;
}

function _sol_lote(PDO $pdo, int $id): array {
    $st = $pdo->prepare('SELECT * FROM proc_lote WHERE id = ?');
    $st->execute([$id]);
    $l = $st->fetch();
    if (!$l || $l['estado'] === 'ANULADA') throw new ErrorApi('Solicitud no encontrada.', 404);
    return $l;
}

/** Normaliza lo digitado de un producto. */
function _sol_normalizar(array $d): array {
    $out = [];
    foreach (array_keys(SOL_CAMPOS) as $k) {
        $v = trim(preg_replace('/\s+/u', ' ', (string) ($d[$k] ?? '')));
        $out[$k] = $v === '' ? null : mb_strtoupper($v);
    }
    // RIPS: «NOMBRE-CODIGO» (como el Excel) o solo el código -> se guarda el código (cambio C3)
    foreach (['forma_farmaceutica_rips', 'concentracion_rips', 'unidad_medida_rips'] as $k) {
        if ($out[$k] !== null && str_contains($out[$k], '-')) $out[$k] = trim(substr($out[$k], strrpos($out[$k], '-') + 1));
    }
    if ($out['unidad_presentacion'] !== null) {
        if (!preg_match('/^\d{1,6}$/', $out['unidad_presentacion'])) throw new ErrorApi('La unidad de presentación debe ser un número entero.', 422);
        $out['unidad_presentacion'] = (int) $out['unidad_presentacion'];
    }
    // FECHA REG INVIMA acepta NO APLICA (p.ej. preparados): la columna es fecha, así que se marca aparte
    $out['_fecha_no_aplica'] = in_array($out['fecha_reg_invima'], SOL_ESPECIALES, true);
    if ($out['_fecha_no_aplica']) $out['fecha_reg_invima'] = null;
    if ($out['fecha_reg_invima'] !== null) {
        $f = substr($out['fecha_reg_invima'], 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) throw new ErrorApi('La fecha INVIMA debe tener el formato AAAA-MM-DD.', 422);
        $out['fecha_reg_invima'] = $f;
    }
    if ($out['iva'] !== null && !in_array($out['iva'], ['SIN IVA', 'CON IVA'], true)) throw new ErrorApi('El IVA debe ser SIN IVA o CON IVA.', 422);
    return $out;
}

/** Crea o edita un producto de la solicitud (solo en BORRADOR o VALIDADA). Queda sin validar hasta «Validar». */
function sol_item_guardar(PDO $pdo, int $loteId, array $d, int $usuarioId): int {
    $lote = _sol_lote($pdo, $loteId);
    if (!in_array($lote['estado'], ['BORRADOR', 'VALIDADA'], true)) throw new ErrorApi('La solicitud ya no se puede modificar.', 409);
    $datos = _sol_normalizar($d);
    // laboratorio digitado con otros puntos o espacios (o uno repetido inactivado): se guarda con el nombre del catálogo
    if ($datos['laboratorio'] !== null && ($lab = laboratorio_activo_por_nombre($pdo, $datos['laboratorio']))) $datos['laboratorio'] = mb_strtoupper($lab['nombre']);
    $fechaNoAplica = $datos['_fecha_no_aplica']; unset($datos['_fecha_no_aplica']);
    // datos para las aprobaciones (molécula, laboratorio o presentación nuevos; POS / NO POS en ASIGNAR)
    $propuesta = array_filter([
        'molecula'     => is_array($d['molecula_nueva'] ?? null) ? $d['molecula_nueva'] : null,
        'asignar_pos'  => in_array($d['asignar_pos'] ?? '', ['POS', 'NO POS'], true) ? $d['asignar_pos'] : null,
        'presentacion' => is_array($d['presentacion_nueva'] ?? null) ? $d['presentacion_nueva'] : null,
        'fecha_no_aplica' => $fechaNoAplica ?: null,
    ]);
    $datos += ['datos_propuestos' => $propuesta ? json_encode($propuesta, JSON_UNESCAPED_UNICODE) : null,
               'resultado' => 'SIN VALIDAR', 'actualizado_en' => ahora()];
    $id = (int) ($d['id'] ?? 0);
    if ($id) {
        $st = $pdo->prepare('SELECT * FROM proc_lote_item WHERE id = ? AND lote_id = ?');
        $st->execute([$id, $loteId]);
        $antes = $st->fetch();
        if (!$antes) throw new ErrorApi('Producto no encontrado en la solicitud.', 404);
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($datos)));
        $pdo->prepare("UPDATE proc_lote_item SET $sets WHERE id = ?")->execute([...array_values($datos), $id]);
        auditar($usuarioId, 'SOLICITUD_ITEM_EDITAR', 'proc_lote_item', $id, array_intersect_key($antes, $datos), $datos);
    } else {
        $fila = (int) $pdo->query('SELECT COALESCE(MAX(fila), 0) + 1 FROM proc_lote_item WHERE lote_id = ' . $loteId)->fetchColumn();
        $id = insertar($pdo, 'proc_lote_item', $datos + ['lote_id' => $loteId, 'fila' => $fila]);
        auditar($usuarioId, 'SOLICITUD_ITEM_CREAR', 'proc_lote_item', $id, null, $datos);
    }
    $pdo->prepare("UPDATE proc_lote SET estado = 'BORRADOR' WHERE id = ?")->execute([$loteId]);
    return $id;
}

function sol_item_eliminar(PDO $pdo, int $loteId, int $itemId, int $usuarioId): void {
    $lote = _sol_lote($pdo, $loteId);
    if (!in_array($lote['estado'], ['BORRADOR', 'VALIDADA'], true)) throw new ErrorApi('La solicitud ya no se puede modificar.', 409);
    $pdo->prepare('DELETE FROM proc_resultado WHERE lote_item_id = ?')->execute([$itemId]);
    $pdo->prepare("UPDATE proc_aprobacion SET estado = 'ANULADA' WHERE lote_item_id = ? AND estado = 'PENDIENTE'")->execute([$itemId]);
    $st = $pdo->prepare('DELETE FROM proc_lote_item WHERE id = ? AND lote_id = ?');
    $st->execute([$itemId, $loteId]);
    if (!$st->rowCount()) throw new ErrorApi('Producto no encontrado en la solicitud.', 404);
    auditar($usuarioId, 'SOLICITUD_ITEM_ELIMINAR', 'proc_lote_item', $itemId, null, null);
}

/** La solicitud con sus productos, resultados y aprobaciones. */
function sol_detalle(PDO $pdo, int $loteId): array {
    $lote = _sol_lote($pdo, $loteId);
    $st = $pdo->prepare('SELECT * FROM proc_lote_item WHERE lote_id = ? ORDER BY fila');
    $st->execute([$loteId]);
    $items = $st->fetchAll();
    $res = [];
    $st = $pdo->prepare('SELECT r.* FROM proc_resultado r JOIN proc_lote_item i ON i.id = r.lote_item_id WHERE i.lote_id = ? ORDER BY r.severidad, r.regla_codigo');
    $st->execute([$loteId]);
    foreach ($st as $r) $res[$r['lote_item_id']][] = $r;
    $apr = [];
    $st = $pdo->prepare("SELECT a.* FROM proc_aprobacion a JOIN proc_lote_item i ON i.id = a.lote_item_id WHERE i.lote_id = ? AND a.estado <> 'ANULADA'");
    $st->execute([$loteId]);
    foreach ($st as $a) $apr[$a['lote_item_id']][] = $a + ['datos' => json_decode((string) $a['datos'], true)];
    foreach ($items as &$i) {
        $i['calculado'] = json_decode((string) $i['datos_calculados'], true) ?: [];
        $i['propuesta'] = json_decode((string) $i['datos_propuestos'], true) ?: [];
        $i['resultados'] = $res[$i['id']] ?? [];
        $i['aprobaciones'] = $apr[$i['id']] ?? [];
        unset($i['datos_calculados'], $i['datos_propuestos']);
    }
    $conteo = array_count_values(array_column($items, 'resultado'));
    return ['solicitud' => $lote, 'productos' => $items, 'conteo' => $conteo];
}

// ------------------------------------------------------------------------------------------- autocompletar
/**
 * Lo que se llena solo en el formulario del producto: COD GEN por el Cód. Audifarma; INVIMA por el CUM (registro,
 * estado, fecha de vencimiento, nombre del producto); Maestra Mutual por el CUM (ATC, IUM, nombre comercial).
 */
function sol_autocompletar(PDO $pdo, string $codAuf, string $cum): array {
    $e = _u($codAuf); $c = _u($cum);
    $r = ['molecula' => null, 'invima' => null, 'mutual' => null];
    if ($e !== '') {
        $st = $pdo->prepare('SELECT * FROM cat_molecula WHERE UPPER(cod_gen_audifarma) = ?');
        $st->execute([$e]);
        $r['molecula'] = $st->fetch() ?: null;
    }
    if ($c !== '' && !in_array($c, SOL_ESPECIALES, true)) {
        $ci = _carga_vigente($pdo, 'INVIMA');
        $st = $pdo->prepare('SELECT c.cum, c.estado_cum, c.registro_sanitario, c.estado_registro, c.producto, c.titular, r.fecha_vencimiento
                               FROM ref_invima_cum c LEFT JOIN ref_invima_registro r ON r.carga_id = c.carga_id AND r.registro_sanitario = c.registro_sanitario
                              WHERE c.carga_id = ? AND c.cum = ? ORDER BY c.id LIMIT 1');
        $st->execute([$ci, $c]);
        $r['invima'] = $st->fetch() ?: null;
        $st = $pdo->prepare('SELECT id_mutual, codigo_atc, codigo_ium, nombre_comercial, medicamento FROM ref_medicamentos_mutual WHERE carga_id = ? AND cum = ? ORDER BY id LIMIT 1');
        $st->execute([_carga_vigente($pdo, 'MEDICAMENTOS_MUTUAL'), $c]);
        $r['mutual'] = $st->fetch() ?: null;
        // ID MUTUAL de las líneas de la Maestra Tarifaria (el que se usa al crear)
        $st = $pdo->prepare('SELECT contrato, cod_mutual FROM nt_item WHERE activo = 1 AND UPPER(cum_facturar) = ? ORDER BY contrato');
        $st->execute([$c]);
        $r['nt'] = $st->fetchAll();
    }
    // si el CUM no tiene ID MUTUAL, el de la línea de la Maestra Tarifaria con el mismo COD AUF (prefiere EVENTO)
    if ($e !== '') {
        $st = $pdo->prepare("SELECT contrato, cod_mutual, cum_facturar FROM nt_item WHERE activo = 1 AND UPPER(cod_auf) = ? AND cod_mutual IS NOT NULL AND cod_mutual <> ''
                             ORDER BY CASE contrato WHEN 'EVENTO' THEN 0 WHEN 'EVENTO SAS' THEN 1 ELSE 2 END, id LIMIT 1");
        $st->execute([$e]);
        $r['nt_cod_auf'] = $st->fetch() ?: null;
    }
    $r['subplanes'] = sol_subplanes_previstos($pdo, $e, $c, $r['molecula']['pos_no_pos'] ?? null);
    return $r;
}

/**
 * Subplanes que tendrá el producto (para mostrarlos en el formulario antes de guardar). Misma regla de la validación
 * (E15): Listado Cápita → CAPITA PBS; Listado EVENTO SAS → EVENTO SAS SIN AUTORIZACION; Contrato Evento → EVENTO POS
 * (EVENTO NO PBS si el COD GEN es NO POS). Cada uno indica si se puede crear (EVENTO exige estar en el contrato).
 */
function sol_subplanes_previstos(PDO $pdo, string $e, string $c, ?string $posNoPos): array {
    if ($e === '' && $c === '') return [];
    $q = function (string $sql, array $p) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); };
    $out = [];
    if ($e !== '' && $q('SELECT 1 FROM ref_capita WHERE carga_id = ? AND UPPER(codigo_cup) = ? ORDER BY id', [_carga_vigente($pdo, 'LISTADO_CAPITA'), $e]))
        $out[] = ['subplan' => 'CAPITA PBS', 'fuente' => 'Listado Cápita', 'ok' => true, 'nota' => null];
    if ($q("SELECT 1 FROM ref_evento_sas WHERE carga_id = ? AND ((cum IS NOT NULL AND cum <> '' AND UPPER(cum) = ?) OR ((cum IS NULL OR cum = '') AND UPPER(cod_audifarma) = ?))", [_carga_vigente($pdo, 'LISTADO_EVENTO_SAS'), $c, $e]))
        $out[] = ['subplan' => 'EVENTO SAS SIN AUTORIZACION', 'fuente' => 'Listado EVENTO SAS', 'ok' => true, 'nota' => null];
    $mutual = $c !== '' ? $q("SELECT cod_mutual FROM nt_item WHERE activo = 1 AND contrato = 'EVENTO' AND UPPER(cum_facturar) = ?", [$c]) : false;
    $evento = _u($posNoPos) === 'NO POS' ? 'EVENTO NO PBS' : 'EVENTO POS';
    if ($mutual !== false && $mutual !== null) {
        $enContrato = (bool) $q('SELECT 1 FROM ref_contrato_evento WHERE carga_id = ? AND UPPER(id_mutual) = ? ORDER BY id', [_carga_vigente($pdo, 'CONTRATO_EVENTO_22382'), _u($mutual)]);
        if ($enContrato || !$out) $out[] = ['subplan' => $evento, 'fuente' => 'Contrato Evento', 'ok' => $enContrato,
            'nota' => $enContrato ? null : "El ID MUTUAL $mutual no está en el Contrato Evento: no se crea hasta que lo incluyan"];
    } elseif (!$out && $c !== '') {
        $out[] = ['subplan' => $evento, 'fuente' => 'Contrato Evento', 'ok' => false, 'nota' => 'El CUM no está en la Maestra Tarifaria: se envía solicitud al Administrador para incluirlo'];
    }
    return $out;
}

// ------------------------------------------------------------------------------------------- motor
/**
 * Valida todos los productos de la solicitud: calcula lo derivado (código de producto, subplanes, línea de la Maestra Tarifaria,
 * precio) y aplica las reglas. Guarda los resultados (proc_resultado), el estado de cada producto y las
 * aprobaciones pendientes. Las consultas son por conjunto: los catálogos y bases se leen una vez.
 */
function sol_validar(PDO $pdo, int $loteId, int $usuarioId): array {
    $lote = _sol_lote($pdo, $loteId);
    if (!in_array($lote['estado'], ['BORRADOR', 'VALIDADA'], true)) throw new ErrorApi('La solicitud ya no se puede validar.', 409);
    $st = $pdo->prepare('SELECT * FROM proc_lote_item WHERE lote_id = ? ORDER BY fila');
    $st->execute([$loteId]);
    $items = $st->fetchAll();
    if (!$items) throw new ErrorApi('La solicitud no tiene productos.', 422);
    $cums = array_values(array_unique(array_filter(array_map(fn($i) => _u($i['cum']), $items))));
    $enCums = $cums ? implode(',', array_fill(0, count($cums), '?')) : "''";
    $q = function (string $sql, array $p = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st; };

    // --- catálogos
    $molecula = [];
    foreach ($pdo->query('SELECT * FROM cat_molecula') as $m) $molecula[_u($m['cod_gen_audifarma'])] = $m;
    $presentacion = [];
    foreach ($pdo->query('SELECT presentacion_agrupada, codigo, unidad_orden, activo FROM cat_presentacion') as $p) $presentacion[_u($p['presentacion_agrupada'])] = $p;
    $laboratorio = [];
    $labClave = []; // laboratorios activos por clave (sin puntos ni espacios): el repetido inactivado se resuelve al que quedó
    foreach ($pdo->query('SELECT nombre, codigo, activo FROM cat_laboratorio') as $l) {
        $laboratorio[_u($l['nombre'])] = $l;
        if ((int) $l['activo']) $labClave[laboratorio_clave($l['nombre'])] = $l;
    }
    $subplan = [];
    foreach ($pdo->query('SELECT id_subplan, nombre, activo FROM cat_subplan') as $s) $subplan[_u($s['nombre'])] = $s;
    $rips = [];
    foreach ($pdo->query('SELECT tabla, codigo, habilitado FROM cat_rips') as $x) $rips[$x['tabla']][_u($x['codigo'])] = (int) $x['habilitado'];
    $creados = array_fill_keys($pdo->query('SELECT codigo_producto_completo FROM prod_producto')->fetchAll(PDO::FETCH_COLUMN), true);

    // --- bases (solo lo que corresponde a la solicitud)
    $cs = _carga_vigente($pdo, 'MAESTRO_SIESA');
    $siesaRef = array_fill_keys($q('SELECT referencia FROM ref_siesa_item WHERE carga_id = ? ORDER BY id', [$cs])->fetchAll(PDO::FETCH_COLUMN), true);
    $siesaCum = [];
    foreach ($q("SELECT cum, referencia, estado FROM ref_siesa_item WHERE carga_id = ? AND cum IN ($enCums) ORDER BY id", [$cs, ...$cums]) as $r) $siesaCum[_u($r['cum'])][] = $r;
    $molMedicar = array_fill_keys($q('SELECT DISTINCT codigo_molecula FROM ref_medicar WHERE carga_id = ?', [_carga_vigente($pdo, 'MAESTRA_MEDICAR')])->fetchAll(PDO::FETCH_COLUMN), true);
    $invima = [];
    foreach ($q("SELECT c.cum, c.estado_cum, c.registro_sanitario, c.estado_registro, r.fecha_vencimiento FROM ref_invima_cum c
                  LEFT JOIN ref_invima_registro r ON r.carga_id = c.carga_id AND r.registro_sanitario = c.registro_sanitario
                 WHERE c.carga_id = ? AND c.cum IN ($enCums) ORDER BY c.id", [_carga_vigente($pdo, 'INVIMA'), ...$cums]) as $r) $invima[_u($r['cum'])] = $r;
    $capita = array_fill_keys(array_map('_u', $q('SELECT codigo_cup FROM ref_capita WHERE carga_id = ? ORDER BY id', [_carga_vigente($pdo, 'LISTADO_CAPITA')])->fetchAll(PDO::FETCH_COLUMN)), true);
    $sasCum = []; $sasCod = [];
    foreach ($q('SELECT cod_audifarma, cum FROM ref_evento_sas WHERE carga_id = ? ORDER BY id', [_carga_vigente($pdo, 'LISTADO_EVENTO_SAS')]) as $r) {
        if ((string) $r['cum'] !== '') $sasCum[_u($r['cum'])] = true; else $sasCod[_u($r['cod_audifarma'])] = true;
    }
    // Maestra Tarifaria calculada (estado, motivos, en contrato, precio de cada línea)
    $nt = [];
    foreach (nt_calcular($pdo, true)['filas'] as $f) if ((int) $f['activo']) $nt[_u($f['cum_facturar']) . '|' . $f['contrato']] = $f;
    $desactualizadas = array_values(array_map(fn($b) => $b['nombre'], array_filter(bases_estado_resumen($pdo), fn($b) => $b['desactualizada'])));
    $reglas = $pdo->query('SELECT codigo, base_comparada, accion_sugerida FROM proc_regla')->fetchAll(PDO::FETCH_UNIQUE);
    $inactivas = reglas_inactivas($pdo);                    // una regla inactiva no se aplica
    $personalizadas = reglas_personalizadas($pdo, 'PRODUCTO'); // reglas creadas por el Administrador

    $porCodigo = [];
    $calc = [];
    foreach ($items as $i) {
        $e = _u($i['codigo_generico_audifarma']);
        $m = $molecula[$e] ?? null;
        $presTxt = $i['presentacion_1'] !== null && $i['unidad_presentacion'] !== null ? _u($i['presentacion_1']) . ' X ' . $i['unidad_presentacion'] : null;
        $p = $presTxt !== null ? ($presentacion[$presTxt] ?? null) : null;
        $l = $i['laboratorio'] !== null ? ($labClave[laboratorio_clave($i['laboratorio'])] ?? $laboratorio[_u($i['laboratorio'])] ?? null) : null;
        $codigo = $m && $p && $l && (string) $m['codigo_medicar'] !== '' ? "{$m['codigo_medicar']}_{$p['codigo']}_{$l['codigo']}" : null;
        if ($codigo !== null) $porCodigo[$codigo][] = (int) $i['id'];
        $calc[$i['id']] = compact('m', 'p', 'l', 'codigo', 'presTxt');
    }

    $insRes = $pdo->prepare('INSERT INTO proc_resultado (lote_item_id, regla_codigo, severidad, campo, base_comparada, valor_encontrado, valor_esperado, accion, creado_en) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $resumen = ['VALIDADO' => 0, 'CON ALERTA' => 0, 'ERROR' => 0];
    foreach ($items as $i) {
        $id = (int) $i['id'];
        ['m' => $m, 'p' => $p, 'l' => $l, 'codigo' => $codigo, 'presTxt' => $presTxt] = $calc[$id];
        $e = _u($i['codigo_generico_audifarma']); $c = _u($i['cum']);
        $propuesta = json_decode((string) $i['datos_propuestos'], true) ?: [];
        $out = [];
        $add = function (string $regla, string $nivel, ?string $campo, $encontrado, $esperado, ?string $accion = null) use (&$out, $reglas, $inactivas) {
            if (isset($inactivas[$regla])) return;
            $out[] = [$regla, $nivel, $campo, $reglas[$regla]['base_comparada'] ?? null, $encontrado === null ? null : mb_substr((string) $encontrado, 0, 500),
                      $esperado === null ? null : mb_substr((string) $esperado, 0, 500), $accion ?? ($reglas[$regla]['accion_sugerida'] ?? null)];
        };
        $aprobaciones = [];
        $especialCum = in_array($c, SOL_ESPECIALES, true) || in_array(_u($i['codigo_cum_auxiliar']), SOL_ESPECIALES, true);
        $especialReg = in_array(_u($i['registro_sanitario']), SOL_ESPECIALES, true);
        $insumoPropio = $c !== '' && $c === $e; // insumo: su CUM es su propio código

        // E01 obligatorios (fecha INVIMA obligatoria en insumos, como el Excel)
        $faltan = array_keys(array_filter(SOL_CAMPOS, fn($x, $k) => $x[1] && ($i[$k] === null || $i[$k] === ''), ARRAY_FILTER_USE_BOTH));
        if (_u($i['tipo_medicamento']) !== 'MEDICAMENTOS' && !$i['fecha_reg_invima'] && empty($propuesta['fecha_no_aplica'])) $faltan[] = 'fecha_reg_invima';
        foreach ($faltan as $k) $add('E01', 'ERROR', SOL_CAMPOS[$k][0], '(vacío)', 'Diligenciado');

        // E03 / E17 COD GEN
        if ($e !== '' && !$m) {
            $add('E03', 'ERROR', 'CODIGO GENERICO AUDIFARMA', $e, 'Existir en COD GEN', isset($propuesta['molecula'])
                ? 'Pendiente de aprobación del Administrador (crear en COD GEN)' : 'Diligencie los datos de la molécula para enviarla a aprobación');
            if (isset($propuesta['molecula'])) $aprobaciones[] = ['MOLECULA', $e, $propuesta['molecula'] + ['cod_gen_audifarma' => $e]];
        } elseif ($m && _u($m['pos_no_pos']) === 'ASIGNAR') {
            $add('E17', 'ERROR', 'CATEGORIA PBS (COD GEN)', 'ASIGNAR', 'POS o NO POS', isset($propuesta['asignar_pos'])
                ? "Pendiente de aprobación del Administrador ({$propuesta['asignar_pos']})" : 'Elija POS o NO POS para enviarlo a aprobación');
            if (isset($propuesta['asignar_pos'])) $aprobaciones[] = ['ASIGNAR_POS', $e, ['cod_gen_audifarma' => $e, 'pos_no_pos' => $propuesta['asignar_pos']]];
        }
        // E04 laboratorio / E05 presentación (se diligencian y aprueba el Administrador, como la molécula)
        if ($i['laboratorio'] !== null && !$l) {
            $add('E04', 'ERROR', 'LABORATORIO', $i['laboratorio'], 'Existir en Laboratorios', 'Pendiente de aprobación del Administrador (crear el laboratorio)');
            $aprobaciones[] = ['LABORATORIO', _u($i['laboratorio']), ['nombre' => _u($i['laboratorio'])]];
        }
        if ($presTxt !== null && !$p) {
            $add('E05', 'ERROR', 'PRESENTACION', $presTxt, 'Existir en Presentaciones', isset($propuesta['presentacion'])
                ? 'Pendiente de aprobación del Administrador (crear la presentación)' : 'Diligencie la unidad de orden para enviarla a aprobación');
            if (isset($propuesta['presentacion'])) $aprobaciones[] = ['PRESENTACION', $presTxt, $propuesta['presentacion'] + ['presentacion_agrupada' => $presTxt,
                'envase' => _u($i['presentacion_1']), 'cantidad' => (int) $i['unidad_presentacion']]];
        }
        // Molécula ya en Medicar: el producto SÍ se crea; lo que no se vuelve a crear es la molécula (COD GEN) en el
        // formato 1 de Medicar (fase 6). No se muestra como alerta (decisión del usuario, 2026-10-03).
        $moleculaEnMedicar = $m && (string) $m['codigo_medicar'] !== '' && isset($molMedicar[(string) $m['codigo_medicar']]);
        // E06 / E07 / E14 código de producto
        if ($codigo !== null) {
            if (isset($siesaRef[$codigo])) $add('E06', 'ERROR', 'CODIGO PRODUCTO COMPLETO', $codigo, 'No existir en SIESA');
            if (isset($creados[$codigo])) $add('E07', 'ERROR', 'CODIGO PRODUCTO COMPLETO', $codigo, 'No haberse creado');
            if (count($porCodigo[$codigo]) > 1) $add('E14', 'ERROR', 'CODIGO PRODUCTO COMPLETO', $codigo, 'Único en la solicitud');
        }
        // INVIMA (E09, igual que la Maestra Tarifaria) y SIESA por CUM (E08 / A04)
        $inv = $c !== '' ? ($invima[$c] ?? null) : null;
        $regVigente = $especialReg || $especialCum || $insumoPropio || ($inv && _registro_vigente($inv['estado_registro']));
        if (!$especialCum && !$especialReg && !$insumoPropio && $c !== '') {
            if (!$inv) $add('E09', 'ERROR', 'CUM', $c, 'Existir en INVIMA', 'Verificar el CUM en INVIMA');
            elseif (!$regVigente) $add('E09', 'ERROR', 'REGISTRO SANITARIO', "CUM {$inv['estado_cum']} · registro {$inv['registro_sanitario']} {$inv['estado_registro']}",
                'Registro vigente', 'No se puede crear: el registro INVIMA no está vigente');
            if ($inv) {
                // A08: lo digitado debe coincidir con INVIMA (se llena solo desde INVIMA)
                if ($i['estado_invima'] !== null && _u($i['estado_invima']) !== _u($inv['estado_registro'])) $add('A08', 'ALERTA', 'ESTADO INVIMA', $i['estado_invima'], $inv['estado_registro']);
                if ($i['fecha_reg_invima'] && $inv['fecha_vencimiento'] && substr((string) $inv['fecha_vencimiento'], 0, 10) !== $i['fecha_reg_invima']) {
                    $add('A08', 'ALERTA', 'FECHA REG INVIMA', $i['fecha_reg_invima'], substr((string) $inv['fecha_vencimiento'], 0, 10));
                }
            }
        }
        if (!$especialCum && $c !== '' && isset($siesaCum[$c])) {
            $activos = array_filter($siesaCum[$c], fn($r) => _u($r['estado']) === 'ACTIVO');
            if ($activos) $add('E08', 'ERROR', 'CUM', $c, 'No estar activo en SIESA', 'Ya está creado (PLU ' . implode(', ', array_column($activos, 'referencia')) . '): no se crea');
            // E18 (decisión del usuario, 2026-10-13; antes era la alerta A04): ya existe la creación, solo falta activarla
            else $add('E18', 'ERROR', 'CUM', $c, 'No existir en SIESA', 'Activar el PLU ' . implode(', ', array_column($siesaCum[$c], 'referencia')) . ' en SIESA: el producto ya está creado (inactivo); no se crea de nuevo');
        }
        // E15 subplanes: se asignan solos según las listas en que esté el producto (decisión del usuario, 2026-10-11):
        // Listado Cápita → CAPITA PBS; Listado EVENTO SAS → EVENTO SAS SIN AUTORIZACION; Contrato Evento →
        // EVENTO POS (EVENTO NO PBS si el COD GEN es NO POS). Si está en varias listas, va en todos esos subplanes.
        // Cada subplán se valida con su línea de la Maestra Tarifaria (P5-1) y lleva su precio.
        $lineaEvento = $c !== '' ? ($nt["$c|EVENTO"] ?? null) : null;
        $asignados = []; // subplán => contrato de la Maestra Tarifaria
        if (isset($capita[$e])) $asignados['CAPITA PBS'] = 'CAPITA';
        if (isset($sasCum[$c]) || isset($sasCod[$e])) $asignados['EVENTO SAS SIN AUTORIZACION'] = 'EVENTO SAS';
        // EVENTO: se asigna si está en el contrato. Si no está en ninguna otra lista, el subplán EVENTO se muestra igual
        // (es el que le corresponde) y E10 lo deja en error hasta que lo incluyan en el contrato (no se crea).
        if ($lineaEvento && ($lineaEvento['en_contrato'] === 'SI' || !$asignados)) $asignados[$m && _u($m['pos_no_pos']) === 'NO POS' ? 'EVENTO NO PBS' : 'EVENTO POS'] = 'EVENTO';
        $sug = $lineaEvento === null && $c !== '' && $e !== '' ? nt_sugerencia($pdo, $e, $c) : null; // ID MUTUAL: CUM → Maestra Mutual → Maestra Tarifaria por COD AUF
        $incluirNt = fn(string $k) => $c !== '' && $e !== '' ? ['INCLUIR_NT', "$c|$k", array_filter(['cum_facturar' => $c, 'cod_auf' => $e, 'contrato' => $k,
            'descripcion' => $m['descripcion_general'] ?? null, 'cum' => $c, 'cod_mutual' => $sug['cod_mutual'] ?? null], fn($v) => $v !== null)] : null;
        // en ninguna lista y sin línea EVENTO en la Maestra Tarifaria: se muestra el subplán EVENTO que le corresponde; abajo, E10 y la
        // solicitud al Administrador para incluirlo en la Maestra Tarifaria (no se crea hasta tener la línea)
        if (!$asignados && $c !== '') $asignados[$m && _u($m['pos_no_pos']) === 'NO POS' ? 'EVENTO NO PBS' : 'EVENTO POS'] = 'EVENTO';
        $subplanes = array_keys($asignados);
        foreach ($subplanes as $sp) if (!isset($subplan[$sp]) || !(int) $subplan[$sp]['activo']) $add('E15', 'ERROR', 'CONTRATO MEDICAR (SUB PLAN)', $sp, 'Subplán activo con ID');
        $lineas = []; // subplán => datos de su línea de la Maestra Tarifaria
        foreach ($asignados as $sp => $k) {
            $ln = $nt["$c|$k"] ?? null;
            if (!$ln) {
                $add('E10', 'ERROR', 'CUM', "$c (línea $k)", 'Estar en la Maestra Tarifaria', 'Solicitud al Administrador para incluirlo en la Maestra Tarifaria');
                if ($a = $incluirNt($k)) $aprobaciones[] = $a;
                continue;
            }
            $pv = $ln['precio_venta'] !== null ? (float) $ln['precio_venta'] : null;
            $lineas[$sp] = ['contrato' => $k, 'cod_mutual' => $ln['cod_mutual'], 'precio' => $pv,
                'precio_m' => $ln['precio_maximo'] !== null ? (float) $ln['precio_maximo'] : null];
            if ($ln['en_contrato'] !== 'SI') $add('E10', 'ERROR', 'ID MUTUAL', "{$ln['cod_mutual']} ($k)", 'Estar en el contrato', 'PTE POR INCLUIR EN EL CONTRATO');
            // E12: otros errores de la línea (el «no está en el contrato» ya lo informa E10; NT25 «no está en el
            // Maestro SIESA», NT26 «no está en MEDICAR» y NT30 «sin PLU en SIESA ni en MEDICAR» son justamente lo que se va a crear:
            // NT30 queda como alerta, no bloquea la creación — decisión del usuario, 2026-11-03)
            $erroresNt = array_values(array_filter($ln['motivos'], fn($x) => $x['nivel'] === 'ERROR' && !in_array($x['codigo'], ['NT01', 'NT25', 'NT26', 'NT30'], true)));
            if ($erroresNt) $add('E12', 'ERROR', "Maestra Tarifaria ($k)", implode(' · ', array_map(fn($x) => "{$x['codigo']}: {$x['texto']}", $erroresNt)), 'Sin errores', 'Corregir primero la línea de la Maestra Tarifaria');
            if (in_array('NT30', array_column($ln['motivos'], 'codigo'), true)) $add('E12', 'ALERTA', "Maestra Tarifaria ($k)", 'NT30: sin PLU en SIESA ni en MEDICAR', null,
                'Es el producto que se va a crear: la alerta desaparece al cargar el Maestro SIESA y la Maestra MEDICAR con el producto creado');
            if ($pv === null || $pv <= 1) {
                if ($pv !== null && $pv <= 1 && $ln['precio1_aprobado_en']) $add('A05', 'ALERTA', "PRECIO ($k)", $pv, null, 'Precio 1 aprobado por el Administrador');
                else $add('E13', 'ERROR', "PRECIO ($k)", $pv ?? '(vacío)', 'Precio mayor a 1');
            }
        }
        // Precio de SIESA (lista L01, un solo precio): el de la línea EVENTO; si el producto no está en EVENTO, el de su
        // primera línea (CAPITA → EVENTO SAS). Decisión del usuario P6-3 (2026-10-11).
        $principal = array_key_first(array_filter($lineas, fn($x) => $x['contrato'] === 'EVENTO')) ?? array_key_first($lineas);
        $linea = $principal !== null ? $lineas[$principal] : null;
        // sin subplán (p.ej. no está en el contrato): ID MUTUAL y precio de la línea EVENTO solo como información (sigue en error)
        if (!$linea && $lineaEvento) $linea = ['contrato' => 'EVENTO', 'cod_mutual' => $lineaEvento['cod_mutual'],
            'precio' => $lineaEvento['precio_venta'] !== null ? (float) $lineaEvento['precio_venta'] : null,
            'precio_m' => $lineaEvento['precio_maximo'] !== null ? (float) $lineaEvento['precio_maximo'] : null];
        // sin línea en la Maestra Tarifaria: ID MUTUAL con la misma regla del formulario (CUM → Maestra Mutual → Maestra Tarifaria por COD AUF), solo informativo
        if (!$linea && !empty($sug['cod_mutual'])) $linea = ['contrato' => null, 'cod_mutual' => $sug['cod_mutual'], 'precio' => null, 'precio_m' => null];
        $contratoNt = $linea['contrato'] ?? null;
        $precio = $linea['precio'] ?? null;
        // E16 RIPS
        foreach (['forma_farmaceutica_rips' => 'FFM', 'concentracion_rips' => 'UMM', 'unidad_medida_rips' => 'UPR'] as $k => $t) {
            if ($i[$k] !== null && empty($rips[$t][_u($i[$k])])) $add('E16', 'ERROR', SOL_CAMPOS[$k][0], $i[$k], "Código habilitado en la tabla RIPS $t");
        }
        // reglas personalizadas (Administrador): campo + condición + valor
        foreach ($personalizadas as $rp) {
            if (!regla_cumple($rp, $i[$rp['campo']] ?? null)) $add($rp['codigo'], $rp['severidad'], SOL_CAMPOS[$rp['campo']][0] ?? $rp['campo'], $i[$rp['campo']] ?? '(vacío)',
                regla_esperado($rp), $rp['accion_sugerida'] ?: $rp['descripcion']);
        }
        // A07 bases desactualizadas
        if ($desactualizadas) $add('A07', 'ALERTA', null, implode(', ', $desactualizadas), 'Bases al día', 'Actualizar la base antes de crear');

        $niveles = array_column($out, 1);
        $estado = in_array('ERROR', $niveles, true) ? 'ERROR' : ($niveles ? 'CON ALERTA' : 'VALIDADO');
        $resumen[$estado]++;
        $pdo->prepare('DELETE FROM proc_resultado WHERE lote_item_id = ?')->execute([$id]);
        foreach ($out as $o) $insRes->execute([$id, ...$o, ahora()]);
        $calculado = ['descripcion_generica' => $m['descripcion_general'] ?? null, 'cod_molecula' => $m['codigo_medicar'] ?? null,
            'categoria_pbs' => $m['pos_no_pos'] ?? null, 'presentacion' => $presTxt, 'presentacion_codigo' => $p['codigo'] ?? null,
            'unidad_orden' => $p['unidad_orden'] ?? null, 'laboratorio_codigo' => $l['codigo'] ?? null, 'subplanes' => $subplanes,
            'molecula_en_medicar' => $moleculaEnMedicar,
            'contrato_nt' => $contratoNt, 'cod_mutual' => $linea['cod_mutual'] ?? null, 'precio' => $precio,
            'precio_m' => $linea['precio_m'] ?? null, 'lineas' => $lineas];
        $pdo->prepare('UPDATE proc_lote_item SET codigo_producto_completo = ?, contrato_medicar = ?, categoria_pbs = ?, tipo_operacion = ?, resultado = ?,
                       datos_calculados = ?, actualizado_en = ? WHERE id = ?')
            ->execute([$codigo, implode(' + ', $subplanes), $m['pos_no_pos'] ?? null, 'CREACION', $estado, json_encode($calculado, JSON_UNESCAPED_UNICODE), ahora(), $id]);
        // aprobaciones pendientes (una por tipo + clave: si otro producto ya la pidió, no se repite)
        foreach ($aprobaciones as [$tipo, $clave, $datos]) {
            $ex = $q("SELECT id FROM proc_aprobacion WHERE tipo = ? AND clave = ? AND estado = 'PENDIENTE'", [$tipo, $clave])->fetchColumn();
            if (!$ex) insertar($pdo, 'proc_aprobacion', ['lote_item_id' => $id, 'tipo' => $tipo, 'clave' => $clave, 'datos' => json_encode($datos, JSON_UNESCAPED_UNICODE),
                'estado' => 'PENDIENTE', 'solicitado_por' => $usuarioId, 'solicitado_en' => ahora()]);
        }
    }
    $pdo->prepare("UPDATE proc_lote SET estado = 'VALIDADA', validado_en = ? WHERE id = ?")->execute([ahora(), $loteId]);
    auditar($usuarioId, 'SOLICITUD_VALIDAR', 'proc_lote', $loteId, null, $resumen);
    return $resumen;
}

/** Bases con su alerta de desactualización (A07). */
function bases_estado_resumen(PDO $pdo): array {
    require_once __DIR__ . '/bases.php';
    $out = [];
    foreach (bases_estado($pdo) as $b) {
        if (!(int) ($b['activo'] ?? 1)) continue;
        $out[] = ['nombre' => $b['nombre'], 'desactualizada' => !empty($b['desactualizada'])];
    }
    return $out;
}

// ------------------------------------------------------------------------------------------- aprobaciones
function sol_aprobaciones(PDO $pdo, string $estado = 'PENDIENTE'): array {
    $st = $pdo->prepare("SELECT a.*, i.lote_id, i.fila, i.cum, i.codigo_generico_audifarma, i.nombre_comercial, u.nombre AS solicitado_por_nombre, r.nombre AS resuelto_por_nombre
                           FROM proc_aprobacion a JOIN proc_lote_item i ON i.id = a.lote_item_id
                           LEFT JOIN seg_usuario u ON u.id = a.solicitado_por LEFT JOIN seg_usuario r ON r.id = a.resuelto_por
                          WHERE a.estado = ? ORDER BY a.id DESC LIMIT 500");
    $st->execute([$estado]);
    return array_map(fn($a) => ['datos' => json_decode((string) $a['datos'], true)] + $a, $st->fetchAll());
}

/**
 * El Administrador aprueba o rechaza. Al aprobar se aplica en el catálogo / Maestra Tarifaria:
 *  MOLECULA -> crea el COD GEN · LABORATORIO -> crea el laboratorio · PRESENTACION -> crea la presentación ·
 *  ASIGNAR_POS -> actualiza POS / NO POS del COD GEN · INCLUIR_NT -> agrega el producto a la Maestra Tarifaria (con sus líneas).
 * $ajustes: los datos revisados por el Administrador (reemplazan los propuestos). Luego se revalida la solicitud.
 */
function sol_resolver(PDO $pdo, int $aprobacionId, bool $aprobar, array $ajustes, string $motivo, int $usuarioId): array {
    $st = $pdo->prepare("SELECT a.*, i.lote_id FROM proc_aprobacion a JOIN proc_lote_item i ON i.id = a.lote_item_id WHERE a.id = ?");
    $st->execute([$aprobacionId]);
    $a = $st->fetch();
    if (!$a || $a['estado'] !== 'PENDIENTE') throw new ErrorApi('La aprobación no está pendiente.', 409);
    $datos = $ajustes + (json_decode((string) $a['datos'], true) ?: []);
    $resultado = null;
    if ($aprobar) {
        switch ($a['tipo']) {
            case 'MOLECULA':
                $resultado = molecula_guardar($pdo, $datos + ['activo' => 1], $usuarioId);
                break;
            case 'ASIGNAR_POS':
                $m = $pdo->prepare('SELECT * FROM cat_molecula WHERE UPPER(cod_gen_audifarma) = ?');
                $m->execute([_u($datos['cod_gen_audifarma'])]);
                $mol = $m->fetch();
                if (!$mol) throw new ErrorApi('El COD GEN ya no existe.', 404);
                if (!in_array($datos['pos_no_pos'] ?? '', ['POS', 'NO POS'], true)) throw new ErrorApi('Elija POS o NO POS.', 422);
                $resultado = molecula_guardar($pdo, ['id' => $mol['id'], 'pos_no_pos' => $datos['pos_no_pos']] + $mol, $usuarioId);
                break;
            case 'LABORATORIO':
                $resultado = laboratorio_guardar($pdo, ['nombre' => $datos['nombre'], 'activo' => 1], $usuarioId);
                break;
            case 'PRESENTACION':
                $resultado = presentacion_guardar($pdo, ['envase' => $datos['envase'] ?? '', 'cantidad' => $datos['cantidad'] ?? '', 'unidad_orden' => $datos['unidad_orden'] ?? '', 'activo' => 1], $usuarioId);
                break;
            case 'INCLUIR_NT':
                // el Administrador revisa los datos en el formulario «Nuevo» de la Maestra Tarifaria y con «Incluir» se crean las líneas
                $resultado = !empty($ajustes['_ya_incluido']) ? ['incluido' => true] : nt_guardar($pdo, $datos + ['id' => 0], $usuarioId);
                break;
            default:
                throw new ErrorApi('Tipo de aprobación desconocido.', 422);
        }
    }
    $pdo->prepare('UPDATE proc_aprobacion SET estado = ?, resuelto_por = ?, resuelto_en = ?, motivo = ?, datos = ? WHERE id = ?')
        ->execute([$aprobar ? 'APROBADA' : 'RECHAZADA', $usuarioId, ahora(), mb_substr(trim($motivo), 0, 500) ?: null, json_encode($datos, JSON_UNESCAPED_UNICODE), $aprobacionId]);
    auditar($usuarioId, $aprobar ? 'APROBACION_APROBAR' : 'APROBACION_RECHAZAR', 'proc_aprobacion', $aprobacionId, null, ['tipo' => $a['tipo'], 'clave' => $a['clave'], 'datos' => $datos, 'motivo' => $motivo]);
    // las solicitudes con productos afectados se revalidan
    $lotes = $pdo->prepare("SELECT DISTINCT i.lote_id FROM proc_lote_item i JOIN proc_lote l ON l.id = i.lote_id WHERE l.estado IN ('BORRADOR', 'VALIDADA')
                             AND (i.id = ? OR UPPER(i.codigo_generico_audifarma) = ? OR UPPER(i.laboratorio) = ? OR UPPER(i.cum) = ?)");
    $lotes->execute([$a['lote_item_id'], _u($datos['cod_gen_audifarma'] ?? $datos['cod_auf'] ?? ''), _u($datos['nombre'] ?? ''), _u($datos['cum_facturar'] ?? '')]);
    foreach ($lotes->fetchAll(PDO::FETCH_COLUMN) as $l) if ($aprobar) sol_validar($pdo, (int) $l, $usuarioId);
    return ['aprobada' => $aprobar, 'resultado' => $resultado];
}


// ------------------------------------------------------------------------------------------- listas y exportación
/** Opciones del formulario del producto (de los catálogos): lo que no se digita libre. */
function sol_listas(PDO $pdo): array {
    $lista = function (string $l) use ($pdo) {
        $st = $pdo->prepare('SELECT valor FROM cat_lista_valor WHERE lista = ? AND activo = 1 ORDER BY orden, valor');
        $st->execute([$l]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    };
    $rips = [];
    foreach ($pdo->query('SELECT tabla, codigo, nombre FROM cat_rips WHERE habilitado = 1 ORDER BY nombre') as $r) $rips[$r['tabla']][] = [$r['codigo'], "{$r['nombre']} ({$r['codigo']})"];
    return [
        'iva' => $pdo->query('SELECT etiqueta_iva FROM cat_grupo_impositivo WHERE activo = 1 ORDER BY codigo')->fetchAll(PDO::FETCH_COLUMN),
        'tipo_medicamento' => $lista('TIPO_MEDICAMENTO'),
        'estado_invima' => $lista('ESTADO_INVIMA'),
        'envase' => $lista('ENVASE'),
        'laboratorios' => $pdo->query('SELECT nombre FROM cat_laboratorio WHERE activo = 1 ORDER BY nombre')->fetchAll(PDO::FETCH_COLUMN),
        'presentaciones' => $pdo->query('SELECT presentacion_agrupada FROM cat_presentacion WHERE activo = 1')->fetchAll(PDO::FETCH_COLUMN),
        'rips' => $rips,
        'molecula' => molecula_sugerencias($pdo),
    ];
}

/** Excel con los productos de la solicitud, su estado y por qué (para corregir). */
function sol_exportar(PDO $pdo, int $loteId, string $destino, bool $soloErrores): string {
    $d = sol_detalle($pdo, $loteId);
    $enc = ['FILA', 'ESTADO', ...array_map(fn($x) => $x[0], array_values(SOL_CAMPOS)), 'DESCRIPCION GENERICA', 'CODIGO PRODUCTO COMPLETO', 'SUBPLANES', 'LINEA MAESTRA TARIFARIA', 'PRECIO', 'OBSERVACIONES'];
    $filas = (function () use ($d, $soloErrores) {
        foreach ($d['productos'] as $p) {
            if ($soloErrores && $p['resultado'] === 'VALIDADO') continue;
            yield [$p['fila'], $p['resultado'], ...array_map(fn($k) => $p[$k], array_keys(SOL_CAMPOS)), $p['calculado']['descripcion_generica'] ?? null,
                   $p['codigo_producto_completo'], implode(' + ', $p['calculado']['subplanes'] ?? []), $p['calculado']['contrato_nt'] ?? null, $p['calculado']['precio'] ?? null,
                   implode(' · ', array_map(fn($r) => "{$r['regla_codigo']}: {$r['campo']} = {$r['valor_encontrado']}" . ($r['accion'] ? " → {$r['accion']}" : ''), $p['resultados']))];
        }
    })();
    XlsxWriter::guardar($destino, [['nombre' => 'SOLICITUD', 'encabezados' => $enc, 'filas' => $filas]]);
    return "SOLICITUD $loteId - " . ($soloErrores ? 'A CORREGIR' : 'PRODUCTOS') . ' - ' . date('Ymd') . '.xlsx';
}

// ------------------------------------------------------------------------------- carga masiva (fase 6.9)
/** Encabezados aceptados además del nombre de SOL_CAMPOS (variantes del Excel FORMATO). */
const SOL_ALIAS = ['CONCENTRACION RIPS' => 'concentracion_rips', 'CODIGO GENERICO' => 'codigo_generico_audifarma', 'COD AUF' => 'codigo_generico_audifarma'];

/** Plantilla Excel para cargar varios productos: hoja FORMATO (las 17 columnas digitadas), INSTRUCCIONES y LISTAS. */
function sol_plantilla(PDO $pdo, string $destino): string {
    $l = sol_listas($pdo);
    $ayuda = [
        'codigo_generico_audifarma' => 'COD AUF del catálogo COD GEN. Si no existe, el producto queda pendiente de aprobación (se diligencia en el formulario)',
        'iva' => 'SIN IVA o CON IVA', 'cum' => 'CUM (expediente-consecutivo). Insumos: su código. NO APLICA / VITAL NO DISPONIBLE / PREPARADO MAGISTRAL',
        'codigo_cum_auxiliar' => 'Si existe colocarlo; si no: NO APLICA / VITAL NO DISPONIBLE / PREPARADO MAGISTRAL',
        'registro_sanitario' => 'Vacío = se toma de INVIMA por el CUM', 'codigo_ium' => 'Vacío = se toma de la Maestra Mutual; si no existe: NO APLICA',
        'nombre_comercial' => 'Vacío = se toma de la Maestra Mutual', 'laboratorio' => 'Nombre como en el catálogo Laboratorios (hoja LISTAS). Si no existe queda pendiente de aprobación',
        'codigo_atc' => 'Vacío = se toma de la Maestra Mutual; si no existe: NO APLICA', 'tipo_medicamento' => 'Hoja LISTAS',
        'fecha_reg_invima' => 'Fecha (AAAA-MM-DD o fecha de Excel). Vacío = vencimiento del registro en INVIMA. Obligatoria en insumos',
        'estado_invima' => 'Vacío = estado del registro en INVIMA', 'presentacion_1' => 'Envase (hoja LISTAS): CAJA, FRASCO…',
        'unidad_presentacion' => 'Número entero: CAJA X 30 → 30', 'forma_farmaceutica_rips' => 'Código TABLA-FFM o «NOMBRE-CODIGO» como en el Excel',
        'concentracion_rips' => 'Código TABLA-UMM o «NOMBRE-CODIGO»', 'unidad_medida_rips' => 'Código TABLA-UPR o «NOMBRE-CODIGO»',
    ];
    $instr = [];
    foreach (SOL_CAMPOS as $k => [$enc, $oblig]) $instr[] = [$enc, $oblig ? 'SI' : ($k === 'fecha_reg_invima' ? 'En insumos' : 'NO'), $ayuda[$k] ?? ''];
    $instr[] = ['', '', ''];
    $instr[] = ['Cómo se carga', '', 'Una fila por producto desde la fila 2. También sirve la hoja FORMATO del Excel CREAR ITEMS (encabezados en cualquiera de las primeras filas; las demás columnas se ignoran).'];
    $instr[] = ['', '', 'Antes de guardar se muestra la vista previa con el resultado de la validación de cada fila. Los productos quedan en «Crear productos»; los que tengan error se corrigen allí.'];
    $listas = ['IVA' => $l['iva'], 'TIPO DE MEDICAMENTO' => $l['tipo_medicamento'], 'ESTADO INVIMA' => $l['estado_invima'], 'PRESENTACION 1' => $l['envase'],
               'LABORATORIO' => $l['laboratorios'], 'FORMA FARMACEUTICA RIPS' => array_map(fn($x) => $x[1], $l['rips']['FFM'] ?? []),
               'CONCENTRACION MEDICAMENTO RIPS' => array_map(fn($x) => $x[1], $l['rips']['UMM'] ?? []), 'UNIDAD MEDIDA RIPS' => array_map(fn($x) => $x[1], $l['rips']['UPR'] ?? [])];
    $max = max(array_map('count', $listas));
    $filasListas = [];
    for ($i = 0; $i < $max; $i++) $filasListas[] = array_map(fn($v) => $v[$i] ?? null, array_values($listas));
    XlsxWriter::guardar($destino, [
        ['nombre' => 'FORMATO', 'encabezados' => array_map(fn($x) => $x[0], array_values(SOL_CAMPOS)), 'filas' => []],
        ['nombre' => 'INSTRUCCIONES', 'encabezados' => ['COLUMNA', 'OBLIGATORIA', 'CÓMO DILIGENCIARLA'], 'filas' => $instr],
        ['nombre' => 'LISTAS', 'encabezados' => array_keys($listas), 'filas' => $filasListas],
    ]);
    return 'PLANTILLA CREAR PRODUCTOS.xlsx';
}

/**
 * Carga varios productos desde Excel (la plantilla o la hoja FORMATO del Excel actual). Cada fila se guarda y se
 * valida igual que con el formulario (las celdas vacías de INVIMA y códigos se llenan solas). Vista previa: todo
 * ocurre dentro de una transacción que se deshace; al aplicar se repite y se confirma.
 * Filas que no se pueden leer (fecha o número inválido) o que ya están en la lista se informan y no se cargan.
 */
function sol_importar(PDO $pdo, string $ruta, string $nombre, int $usuarioId, bool $aplicar): array {
    require_once __DIR__ . '/xlsx_reader.php';
    $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xlsm'], true)) throw new ErrorApi('Use un archivo de Excel (.xlsx).', 422);
    $x = new XlsxReader($ruta);
    $norm = fn($s) => trim(preg_replace('/\s+/u', ' ', strtr(mb_strtoupper((string) $s), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U'])));
    $porEnc = [];
    foreach (SOL_CAMPOS as $k => [$enc]) $porEnc[$norm($enc)] = $k;
    $porEnc += SOL_ALIAS;
    // hoja: FORMATO primero; si no, la primera que tenga el encabezado CODIGO GENERICO AUDIFARMA
    $hojas = $x->hojas();
    usort($hojas, fn($a, $b) => (int) ($norm($b) === 'FORMATO') <=> (int) ($norm($a) === 'FORMATO'));
    $hoja = null; $cols = []; $filaEnc = null;
    foreach ($hojas as $h) {
        foreach ($x->filas($h) as $num => $f) {
            $c = [];
            foreach ($f as $col => $v) if (isset($porEnc[$norm($v)])) $c[$porEnc[$norm($v)]] ??= $col;
            if (isset($c['codigo_generico_audifarma']) && count($c) >= 5) { $hoja = $h; $cols = $c; $filaEnc = $num; break 2; }
            if ($num >= 30) break;
        }
    }
    if (!$hoja) throw new ErrorApi('No se encontró la hoja con los encabezados (CODIGO GENERICO AUDIFARMA, CUM, LABORATORIO…). Use la plantilla.', 422);
    $faltan = array_values(array_map(fn($k) => SOL_CAMPOS[$k][0], array_diff(array_keys(SOL_CAMPOS), array_keys($cols))));

    $pdo->beginTransaction();
    try {
        $lote = sol_abierta($pdo, $usuarioId);
        $ya = [];
        foreach ($pdo->query('SELECT UPPER(codigo_generico_audifarma) AS e, UPPER(cum) AS c FROM proc_lote_item WHERE lote_id = ' . $lote) as $r) $ya["{$r['e']}|{$r['c']}"] = true;
        $nuevos = []; $omitidas = [];
        foreach ($x->filas($hoja) as $num => $f) {
            if ($num <= $filaEnc) continue;
            $d = [];
            foreach ($cols as $k => $col) $d[$k] = isset($f[$col]) ? trim((string) $f[$col]) : '';
            if (implode('', $d) === '') continue;
            if ($d['codigo_generico_audifarma'] === '') { $omitidas[] = ['fila' => $num, 'motivo' => 'Sin CODIGO GENERICO AUDIFARMA']; continue; }
            foreach (['codigo_generico_audifarma', 'cum', 'unidad_presentacion'] as $k) if (isset($d[$k])) $d[$k] = codigo_excel($d[$k]);
            if (($d['fecha_reg_invima'] ?? '') !== '') {
                $fv = $d['fecha_reg_invima'];
                if (is_numeric($fv)) $d['fecha_reg_invima'] = XlsxReader::fechaSerial($fv);
                elseif (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', $fv, $m)) $d['fecha_reg_invima'] = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            }
            $clave = _u($d['codigo_generico_audifarma']) . '|' . _u($d['cum'] ?? '');
            if (isset($ya[$clave])) { $omitidas[] = ['fila' => $num, 'motivo' => "Ya está en la lista por crear (COD AUF {$d['codigo_generico_audifarma']}, CUM {$d['cum']})"]; continue; }
            // celdas vacías: se llenan como en el formulario (INVIMA y Maestra Mutual)
            $auto = sol_autocompletar($pdo, $d['codigo_generico_audifarma'], $d['cum'] ?? '');
            $inv = $auto['invima']; $mu = $auto['mutual'];
            $llenar = function (string $k, $v) use (&$d) { if (($d[$k] ?? '') === '' && $v !== null && $v !== '') $d[$k] = (string) $v; };
            if ($inv) {
                $llenar('registro_sanitario', $inv['registro_sanitario']);
                $llenar('estado_invima', $inv['estado_registro'] !== null ? mb_convert_case((string) $inv['estado_registro'], MB_CASE_TITLE) : null);
                $llenar('fecha_reg_invima', $inv['fecha_vencimiento'] ? substr((string) $inv['fecha_vencimiento'], 0, 10) : null);
            }
            if ($mu) { $llenar('codigo_ium', $mu['codigo_ium']); $llenar('codigo_atc', $mu['codigo_atc']); $llenar('nombre_comercial', $mu['nombre_comercial']); }
            try {
                $sp = 'imp' . $num;
                $pdo->exec("SAVEPOINT $sp");
                $id = sol_item_guardar($pdo, $lote, $d, $usuarioId);
                $pdo->exec("RELEASE SAVEPOINT $sp");
            } catch (ErrorApi $e) {
                $pdo->exec("ROLLBACK TO SAVEPOINT $sp");
                $omitidas[] = ['fila' => $num, 'motivo' => $e->getMessage()];
                continue;
            }
            $ya[$clave] = true;
            $nuevos[$id] = $num;
        }
        $resultados = [];
        if ($nuevos) {
            sol_validar($pdo, $lote, $usuarioId);
            $marcas = implode(',', array_keys($nuevos));
            $reg = [];
            foreach ($pdo->query("SELECT lote_item_id, regla_codigo, severidad, campo, valor_encontrado, accion FROM proc_resultado WHERE lote_item_id IN ($marcas) ORDER BY id") as $r) $reg[$r['lote_item_id']][] = $r;
            foreach ($pdo->query("SELECT id, codigo_generico_audifarma, cum, laboratorio, codigo_producto_completo, contrato_medicar, resultado FROM proc_lote_item WHERE id IN ($marcas)") as $r) {
                $resultados[] = ['fila_excel' => $nuevos[$r['id']], 'cod_auf' => $r['codigo_generico_audifarma'], 'cum' => $r['cum'], 'laboratorio' => $r['laboratorio'],
                    'codigo' => $r['codigo_producto_completo'], 'subplanes' => $r['contrato_medicar'], 'resultado' => $r['resultado'],
                    'motivos' => array_map(fn($m) => "{$m['regla_codigo']}: " . ($m['campo'] ? "{$m['campo']} = " : '') . ($m['valor_encontrado'] ?? '') . ($m['accion'] ? " → {$m['accion']}" : ''), $reg[$r['id']] ?? [])];
            }
            usort($resultados, fn($a, $b) => $a['fila_excel'] <=> $b['fila_excel']);
        }
        $cuenta = array_count_values(array_column($resultados, 'resultado'));
        $resumen = ['hoja' => $hoja, 'fila_encabezado' => $filaEnc, 'columnas_faltantes' => $faltan, 'filas' => count($resultados) + count($omitidas),
            'cargados' => count($resultados), 'validados' => $cuenta['VALIDADO'] ?? 0, 'con_alerta' => $cuenta['CON ALERTA'] ?? 0, 'con_error' => $cuenta['ERROR'] ?? 0,
            'omitidas' => count($omitidas)];
        if ($aplicar) {
            if (!$resultados) throw new ErrorApi('No hay filas para cargar.', 422);
            auditar($usuarioId, 'SOLICITUD_IMPORTAR', 'proc_lote', $lote, null, ['archivo' => $nombre, 'hash' => hash_file('sha256', $ruta)] + $resumen);
            $pdo->commit();
        } else {
            $pdo->rollBack();
        }
        return ['resumen' => $resumen, 'productos' => $resultados, 'omitidas' => $omitidas, 'aplicado' => $aplicar];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
