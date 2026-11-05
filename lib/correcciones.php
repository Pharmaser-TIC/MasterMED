<?php
/**
 * Módulo de corrección de errores en SIESA y MEDICAR (pedido del usuario, 2026-10-19; retoma 7I, 7K, 7L y 7M).
 *
 * La Maestra Tarifaria manda: cada CUM FACTURAR se cruza con el Maestro SIESA (MED-CUM) y con la Maestra MEDICAR (CUM).
 * Un CUM FACTURAR debe tener un solo PLU, igual en las tres bases. Lo que no coincide queda como inconsistencia:
 *   PENDIENTE_CORRECCION → (se descargan los archivos de cargue) PENDIENTE_ACTUALIZACION → (se recarga la base y ya coincide) CORREGIDO.
 *   NO_VALIDADO: se recargó la base después de descargar y sigue igual (se vuelve a descargar).
 *   PENDIENTE_REVISION: no hay un único valor correcto (las líneas de la Maestra Tarifaria no coinciden entre sí, o hay varios
 *   PLU activos con el mismo CUM): no se descarga hasta revisarlo.
 * El registro sanitario no está en la Maestra Tarifaria: se compara con INVIMA (por el CUM).
 */
require_once __DIR__ . '/creacion.php'; // nombres y estructuras de los archivos de cargue (CRE_ARCHIVOS)

const COR_SISTEMAS = ['SIESA' => 'MAESTRO_SIESA', 'MEDICAR' => 'MAESTRA_MEDICAR'];
const COR_ESTADOS = ['PENDIENTE_CORRECCION' => 'Por corregir', 'NO_VALIDADO' => 'No validado', 'PENDIENTE_REVISION' => 'Pendiente de revisión',
    'PENDIENTE_ACTUALIZACION' => 'Pendiente de actualización', 'CORREGIDO' => 'Corregido', 'APROBADO' => 'Aprobado'];
/** Estados cerrados: se conservan (trazabilidad) pero no se ven con lo pendiente. APROBADO = revisado y aprobado así (varios PLU). */
const COR_CERRADOS = ['CORREGIDO', 'APROBADO'];
const COR_EXPORTABLES = ['PENDIENTE_CORRECCION', 'NO_VALIDADO'];

/**
 * Campos que se validan en SIESA: [nombre, cargue, f104_id]. Cargue: DESCRIPCIONES (Descripciones Técnicas, por f104_id),
 * CREACION_ITEM (grupo impositivo) o MANUAL (no hay estructura de cargue: se corrige en SIESA con la lista).
 */
const COR_CAMPOS_SIESA = [
    'NO_EXISTE'     => ['El CUM no está en SIESA', 'MANUAL', null],
    'PLU_VARIOS'    => ['Más de un PLU con el mismo CUM', 'MANUAL', null],
    'ESTADO'        => ['Estado (activo / inactivo)', 'MANUAL', null],
    'IVA'           => ['IVA (grupo impositivo)', 'CREACION_ITEM', null],
    'COD_AUF'       => ['Cód. Audifarma', 'DESCRIPCIONES', 'CODIGO GENERICO AUDIFARMA'],
    // el ID MUTUAL de SIESA está en MED-CODIGO GENERICO AUXILIAR (MED-ID MUTUAL suele venir en 0 y no se usa): aclaración del usuario, 2026-10-19
    'ID_MUTUAL_AUX' => ['ID MUTUAL (MED-CODIGO GENERICO AUXILIAR)', 'DESCRIPCIONES', 'CODIGO GENERICO AUXILIAR'],
    'TIPO'          => ['Tipo de medicamento', 'DESCRIPCIONES', 'TIPO DE MEDICAMENTO'],
    'REGISTRO'      => ['Registro sanitario (frente a INVIMA)', 'DESCRIPCIONES', 'REGISTRO SANITARIO'],
];

/** Registro sanitario comparable: sin el prefijo INVIMA, espacios, guiones ni puntos («2016 M-002562 R-2» = «2016M-002562-R2»). */
function _cor_registro(?string $v): string {
    return preg_replace('/[^A-Z0-9]/', '', preg_replace('/^\s*INVIMA\s*/', '', _u($v)));
}

/** Tipo de medicamento de SIESA que corresponde al TIPO PRODUCTO de la Maestra Tarifaria (714 medicamento / 799 insumo). */
function _cor_tipo_ok(string $tipoMt, ?string $tipoSiesa): bool {
    $t = _u($tipoSiesa);
    return $tipoMt === '714' ? $t === 'MEDICAMENTOS' : ($t !== '' && !str_starts_with($t, 'MEDICAMENT'));
}

/**
 * Inconsistencias de SIESA calculadas con las bases vigentes, por llave (SIESA|CUM FACTURAR|PLU|campo).
 * Cada una: cum_facturar, plu, item, contratos, campo, actual, esperado, accion, revision.
 */
function cor_calcular_siesa(PDO $pdo): array {
    $carga = _carga_vigente($pdo, 'MAESTRO_SIESA');
    if (!$carga) throw new ErrorApi('No hay Maestro SIESA cargado en Bases de referencia.', 422);
    $mt = [];
    foreach ($pdo->query('SELECT id, cum_facturar, contrato, cum, cod_auf, cod_mutual, iva, tipo_producto, activo FROM nt_item ORDER BY id') as $r) $mt[_u($r['cum_facturar'])][] = $r;
    // la Maestra Tarifaria manda (2026-11-05): estado de cada PLU en la Maestra Tarifaria por línea
    $plusMt = []; $plusPend = []; $varios = [];
    foreach (nt_calcular($pdo)['filas'] as $f) {
        $plusMt[(int) $f['id']] = $f['_plus_mt'] ?? [];
        $plusPend[(int) $f['id']] = $f['_plus_pend'] ?? [];
        if ((int) $f['activo'] && str_contains($f['reglas'], 'NT29')) $varios[_u($f['cum_facturar'])] = true; // CUM con NT29 sin resolver
    }
    $siesa = [];
    $st = $pdo->prepare('SELECT item, referencia, cum, registro_sanitario, cod_gen_audifarma, id_mutual, codigo_generico_auxiliar, grupo_impositivo, estado, tipo_medicamento
                         FROM ref_siesa_item WHERE carga_id = ? AND cum IN (SELECT cum_facturar FROM nt_item) ORDER BY id');
    $st->execute([$carga]);
    foreach ($st as $r) $siesa[_u($r['cum'])][_u($r['referencia'])] = $r;
    $invima = _cor_invima($pdo);
    $ivaGrupo = []; $grupoIva = [];
    foreach ($pdo->query('SELECT codigo, iva_medicar FROM cat_grupo_impositivo WHERE activo = 1 ORDER BY codigo') as $g) {
        $ivaGrupo[$g['codigo']] = (int) $g['iva_medicar'];
        $grupoIva[(int) $g['iva_medicar']] ??= $g['codigo'];
    }

    $out = [];
    foreach ($mt as $cum => $lineas) {
        $activas = array_values(array_filter($lineas, fn($l) => (int) $l['activo'] === 1));
        $activo = (bool) $activas;
        $usar = $activas ?: $lineas;
        $contratos = implode(', ', array_unique(array_map(fn($l) => nt_contrato_vista($l['contrato']), $usar)));
        $cumFacturar = (string) $usar[0]['cum_facturar'];
        $add = function (string $campo, string $plu, ?string $item, ?string $actual, ?string $esperado, string $accion, bool $revision = false)
            use (&$out, $cumFacturar, $contratos) {
            $out["SIESA|$cumFacturar|$plu|$campo"] = ['cum_facturar' => $cumFacturar, 'plu' => $plu, 'item' => $item, 'contratos' => $contratos, 'subplan' => null, 'campo' => $campo,
                'actual' => $actual, 'esperado' => $esperado, 'accion' => $accion, 'revision' => $revision];
        };
        $items = $siesa[$cum] ?? [];
        if (!$items) {
            if ($activo) $add('NO_EXISTE', '', null, null, null, 'Crear el producto en SIESA (Crear productos) o revisar el CUM FACTURAR de la Maestra Tarifaria');
            continue;
        }
        // La Maestra Tarifaria manda (2026-11-05): SIESA es espejo. Cada PLU debe estar en SIESA como está en la Maestra Tarifaria
        // (activo si lo está en alguna línea activa del CUM). Varios PLU activos se resuelven en la Maestra Tarifaria (NT29: inactivar o aprobar),
        // por eso ya no sale «Más de un PLU con el mismo CUM» aquí. Un PLU inactivo en las dos (el que se creó mal y se reemplazó) no se valida.
        $mtPlu = []; // PLU => activo en la Maestra Tarifaria
        foreach ($lineas as $l) foreach ($plusMt[(int) $l['id']] ?? [] as $p => $v) $mtPlu[(string) $p] = ($mtPlu[(string) $p] ?? false) || ((int) $l['activo'] === 1 && $v === 1);
        $pendCum = [];
        foreach ($lineas as $l) foreach ($plusPend[(int) $l['id']] ?? [] as $p) $pendCum[(string) $p] = true;
        // CUM con varios PLU (NT29 sin resolver): se ve aquí, pero se define en la Maestra Tarifaria (pedido del usuario, 2026-11-05)
        if (isset($varios[$cum])) {
            $todos = array_keys(array_filter($mtPlu) + $pendCum);
            foreach ($todos as $p) {
                $p = (string) $p;
                $add('PLU_VARIOS', $p, $items[$p]['item'] ?? null, $p . (isset($pendCum[$p]) ? ' (nuevo en SIESA, por definir)' : ' (activo en la Maestra Tarifaria)'),
                    implode(', ', $todos) . ': debe quedar uno solo activo',
                    "El CUM $cumFacturar tiene varios PLU: definir en la Maestra Tarifaria (NT29) cuál queda («Dejar inactivo» el que sobra) o «Aprobar varios PLU»");
            }
        }
        // valor que da la Maestra Tarifaria para un campo; si sus líneas no coinciden, no hay un único valor (revisión)
        $esperado = function (string $k) use ($usar) {
            $v = array_values(array_unique(array_map(fn($l) => trim((string) $l[$k]), $usar)));
            return [implode(' / ', $v), count($v) > 1];
        };
        foreach ($items as $plu => $i) {
            $plu = (string) $plu;
            $siesaActivo = _u($i['estado']) === 'ACTIVO';
            $mtActivo = $mtPlu[$plu] ?? false;
            if (isset($pendCum[$plu])) continue; // por definir en la Maestra Tarifaria: sale como «Más de un PLU con el mismo CUM»
            if ($siesaActivo !== $mtActivo) {
                $add('ESTADO', $plu, $i['item'], ucfirst(mb_strtolower((string) $i['estado'])), $mtActivo ? 'Activo' : 'Inactivo', $mtActivo
                    ? 'Activar el PLU en SIESA (está activo en la Maestra Tarifaria)'
                    : (!$activo ? 'Inactivar el PLU en SIESA (la línea está inactiva en la Maestra Tarifaria)'
                        : 'Inactivar el PLU en SIESA (está inactivo en la Maestra Tarifaria; si debe quedar activo, actívelo en la Maestra Tarifaria)'));
            }
            if (!$mtActivo) continue; // solo se validan los datos de los PLU activos en la Maestra Tarifaria
            // IVA: grupo impositivo según el IVA de la Maestra Tarifaria
            $ivas = array_values(array_unique(array_map(fn($l) => (int) round((float) $l['iva'] * 100), $usar)));
            $ivaSiesa = $ivaGrupo[(string) $i['grupo_impositivo']] ?? null;
            if (count($ivas) > 1) {
                $add('IVA', $plu, $i['item'], (string) $i['grupo_impositivo'], implode(' / ', array_map(fn($x) => "$x %", $ivas)),
                    'Las líneas de la Maestra Tarifaria tienen IVA distinto: corregir la Maestra Tarifaria primero', true);
            } elseif ($ivaSiesa !== $ivas[0]) {
                $grupo = $grupoIva[$ivas[0]] ?? null;
                $add('IVA', $plu, $i['item'], trim(($i['grupo_impositivo'] ?? '') . ($ivaSiesa !== null ? " ($ivaSiesa %)" : '')), $grupo ? "$grupo ({$ivas[0]} %)" : "{$ivas[0]} %",
                    $grupo ? "Cambiar el grupo impositivo a $grupo" : "No hay grupo impositivo activo con IVA {$ivas[0]} % en el catálogo", !$grupo);
            }
            // códigos: COD AUF → MED-CODIGO GENERICO AUDIFARMA; COD MUTUAL → MED-CODIGO GENERICO AUXILIAR
            foreach ([['COD_AUF', 'cod_auf', 'cod_gen_audifarma', 'MED-CODIGO GENERICO AUDIFARMA'],
                      ['ID_MUTUAL_AUX', 'cod_mutual', 'codigo_generico_auxiliar', 'MED-CODIGO GENERICO AUXILIAR']] as [$campo, $kMt, $kSiesa, $nombre]) {
                [$v, $conflicto] = $esperado($kMt);
                if ($v === '' || (!$conflicto && _u($v) === _u($i[$kSiesa]))) continue;
                $add($campo, $plu, $i['item'], $i[$kSiesa], $v, $conflicto ? 'Las líneas de la Maestra Tarifaria no coinciden: corregir la Maestra Tarifaria primero' : "Cambiar $nombre a $v", $conflicto);
            }
            [$tipo, $conflicto] = $esperado('tipo_producto');
            if ($conflicto) $add('TIPO', $plu, $i['item'], $i['tipo_medicamento'], $tipo, 'Las líneas de la Maestra Tarifaria tienen distinto TIPO PRODUCTO: corregir la Maestra Tarifaria primero', true);
            elseif (in_array($tipo, ['714', '799'], true) && !_cor_tipo_ok($tipo, $i['tipo_medicamento'])) {
                $nuevo = $tipo === '714' ? 'MEDICAMENTOS' : 'INSUMOS';
                $add('TIPO', $plu, $i['item'], $i['tipo_medicamento'], $nuevo, "Cambiar MED-TIPO DE MEDICAMENTO a $nuevo (TIPO PRODUCTO $tipo en la Maestra Tarifaria)");
            }
            $inv = $invima[$cum] ?? null;
            if ($inv && !isset($inv['todos'][_cor_registro($i['registro_sanitario'])])) {
                $add('REGISTRO', $plu, $i['item'], $i['registro_sanitario'], $inv['propuesto'], "Cambiar MED-REGISTRO SANITARIO a {$inv['propuesto']} (registro del CUM en INVIMA)");
            }
        }
    }
    return $out;
}

/**
 * Campos que se validan en MEDICAR: [nombre]. MEDICAR se corrige con su propio formato: PLU + OBSERVACIÓN (decisión del usuario,
 * 2026-10-19), por eso todo se descarga como observación.
 */
const COR_CAMPOS_MEDICAR = [
    'NO_EXISTE' => ['El producto no está en MEDICAR', 'OBSERVACION', null],
    'PLU'       => ['PLU distinto al de SIESA', 'OBSERVACION', null],
    'CUM'       => ['CUM distinto', 'OBSERVACION', null],
    'SUBPLAN'   => ['PLU sin el subplán de la línea', 'OBSERVACION', null],
    'TRANSFERENCIA' => ['Falta en TRANSFERENCIA DE PENDIENTES', 'OBSERVACION', null],
    'ESTADO'    => ['Estado (Opción Cadena)', 'OBSERVACION', null],
    'IVA'       => ['%Iva', 'OBSERVACION', null],
    'PRECIO'    => ['Precio Unidad Mínima con IVA', 'OBSERVACION', null],
    'COD_AUF'   => ['Codigo generico', 'OBSERVACION', null],
    'ID_MUTUAL' => ['Codigo MD (ID MUTUAL)', 'OBSERVACION', null],
    'TIPO'      => ['Tipo Medicamento', 'OBSERVACION', null],
    'REGISTRO'  => ['Registro Invima (frente a INVIMA)', 'OBSERVACION', null],
];

const COR_SUBPLAN_TRANSFERENCIA = 'TRANSFERENCIA DE PENDIENTES CON AUTORIZACION';

/** Campos de un sistema. */
function cor_campos(string $sistema): array { return $sistema === 'MEDICAR' ? COR_CAMPOS_MEDICAR : COR_CAMPOS_SIESA; }

/** Número de MEDICAR (el CSV usa punto decimal: «200.000000» = 200). */
function _cor_num(?string $v): ?float { $v = trim((string) $v); return $v === '' || !is_numeric($v) ? null : (float) $v; }

/** Registros de INVIMA por CUM de la Maestra Tarifaria: los comparables y el que se propone (el vigente primero). */
function _cor_invima(PDO $pdo): array {
    $invima = [];
    $st = $pdo->prepare('SELECT cum, registro_sanitario, estado_registro FROM ref_invima_cum WHERE carga_id = ? AND cum IN (SELECT cum_facturar FROM nt_item) ORDER BY id');
    $st->execute([_carga_vigente($pdo, 'INVIMA')]);
    foreach ($st as $r) {
        if (trim((string) $r['registro_sanitario']) === '') continue;
        $c = _u($r['cum']);
        $invima[$c]['todos'][_cor_registro($r['registro_sanitario'])] = true;
        if (!isset($invima[$c]['propuesto']) || _registro_vigente($r['estado_registro'])) $invima[$c]['propuesto'] = trim(preg_replace('/^\s*INVIMA\s*/i', '', (string) $r['registro_sanitario']));
    }
    return $invima;
}

/**
 * Inconsistencias de MEDICAR, por llave (MEDICAR|CUM FACTURAR|PLU|subplán|campo). La Maestra Tarifaria manda; el PLU es el de SIESA
 * (el activo; sin activo, el del COD AUF). Cada línea (CUM FACTURAR + contrato) se valida en su subplán: CAPITA → CAPITA PBS,
 * EVENTO → EVENTO POS / EVENTO NO PBS (según el COD GEN), EVENTO SAS → EVENTO SAS SIN AUTORIZACION.
 * Precio: ¿PRECIO CORRECTO - M? frente a Precio Unidad Minima con Iva. Un PLU inactivo en MEDICAR con el mismo CUM no es error.
 */
function cor_calcular_medicar(PDO $pdo): array {
    $carga = _carga_vigente($pdo, 'MAESTRA_MEDICAR');
    if (!$carga) throw new ErrorApi('No hay Maestra MEDICAR cargada en Bases de referencia.', 422);
    $siesa = [];
    $st = $pdo->prepare('SELECT referencia, cum, estado FROM ref_siesa_item WHERE carga_id = ? AND cum IN (SELECT cum_facturar FROM nt_item) ORDER BY id');
    $st->execute([_carga_vigente($pdo, 'MAESTRO_SIESA')]);
    foreach ($st as $r) $siesa[_u($r['cum'])][_u($r['referencia'])] = _u($r['estado']) === 'ACTIVO';
    $medPlu = []; $medCum = [];
    $st = $pdo->prepare('SELECT plu_cadena, cum, subplan, opcion, codigo_generico, codigo_md, iva_pct, precio_um_iva, registro_invima, tipo_medicamento FROM ref_medicar WHERE carga_id = ? ORDER BY id');
    $st->execute([$carga]);
    foreach ($st as $r) {
        $p = _u($r['plu_cadena']);
        $medPlu[$p][_u($r['subplan'])] = $r;
        if (trim((string) $r['opcion']) === '1') $medCum[_u($r['cum'])][$p] = true; // PLU activos de MEDICAR por CUM
    }
    $invima = _cor_invima($pdo);
    $variosAprobados = nt_plu_varios_aprobados($pdo); // CUM aprobados con varios PLU activos
    $out = [];
    foreach (nt_calcular($pdo)['filas'] as $l) {
        $cf = (string) $l['cum_facturar']; $cum = _u($cf);
        $activoLinea = (int) $l['activo'] === 1;
        $activo = $activoLinea;
        $sp = (string) ($l['subplan'] ?? '');
        $contrato = (string) $l['contrato_vista'];
        $exento = $l['tipo_producto'] === '799' || _u($l['cum']) === 'VITAL NO DISPONIBLE'; // insumos y vitales: varios PLU y sin CUM en MEDICAR
        $add = function (string $campo, string $plu, ?string $actual, ?string $esperado, string $obs, bool $revision = false) use (&$out, $cf, $contrato, $sp) {
            $out["MEDICAR|$cf|$plu|$sp|$campo"] = ['cum_facturar' => $cf, 'plu' => $plu, 'item' => null, 'contratos' => $contrato, 'subplan' => $sp, 'campo' => $campo,
                'actual' => $actual, 'esperado' => $esperado, 'accion' => $obs, 'revision' => $revision];
        };
        // La Maestra Tarifaria manda (2026-11-05): los PLU de la línea con su estado en la Maestra Tarifaria; MEDICAR es espejo.
        // Sin PLU en SIESA: los activos de MEDICAR por CUM.
        $enSiesa = $siesa[$cum] ?? [];
        $mt = $l['_plus_mt'] ?? [];
        $plus = array_map('strval', array_keys($mt));
        if (!$plus) $plus = array_map('strval', array_keys($medCum[$cum] ?? []));
        if (!$plus) {
            if ($activo) $add('NO_EXISTE', '', null, $sp, "No tiene PLU en SIESA ni en MEDICAR: crearlo en Crear productos (no va en el archivo de MEDICAR)");
            continue;
        }
        // un PLU activo en MEDICAR con este CUM que no es el de SIESA (los inactivos no son error)
        if ($activo && !$exento && $enSiesa) {
            foreach (array_keys($medCum[$cum] ?? []) as $p) {
                if (!in_array($p, $plus, true)) $add('PLU', $p, $p, implode(', ', $plus), "El PLU $p está activo en MEDICAR con el CUM $cf, pero en SIESA el CUM es del PLU " . implode(', ', $plus) . ': inactivarlo o corregir su CUM');
            }
        }
        // varios PLU activos en la Maestra Tarifaria (NT29): primero se define allá cuál queda (salvo que el CUM esté aprobado con varios PLU)
        $activosMt = [...array_map('strval', array_keys(array_filter($mt, fn($v) => $v === 1))), ...($l['_plus_pend'] ?? [])];
        $variosSiesa = $activoLinea && !$exento && count($activosMt) > 1 && !nt_plu_varios_ok($variosAprobados, $cf, $activosMt);
        $pendL = $l['_plus_pend'] ?? [];
        foreach ($plus as $p) {
            if (in_array($p, $pendL, true)) continue; // por definir en la Maestra Tarifaria (NT29)
            $activo = $activoLinea && (!$mt || ($mt[$p] ?? 0) === 1); // estado del PLU en la Maestra Tarifaria (PLU + CONTRATO)
            $filas = $medPlu[$p] ?? null;
            if (!$filas) { if ($activo) $add('PLU', $p, null, $p, "Crear en MEDICAR el PLU $p (CUM $cf) en el subplán $sp"); continue; }
            // todo lo que está en el Contrato Evento debe estar activo en MEDICAR en TRANSFERENCIA DE PENDIENTES (decisión del usuario, 2026-10-19)
            if ($activo && $l['contrato'] === 'EVENTO' && $l['en_contrato'] === 'SI' && !$variosSiesa) {
                $tr = $filas[COR_SUBPLAN_TRANSFERENCIA] ?? null;
                if (!$tr || trim((string) $tr['opcion']) !== '1') {
                    $out["MEDICAR|$cf|$p|" . COR_SUBPLAN_TRANSFERENCIA . '|TRANSFERENCIA'] = ['cum_facturar' => $cf, 'plu' => $p, 'item' => null, 'contratos' => $contrato,
                        'subplan' => COR_SUBPLAN_TRANSFERENCIA, 'campo' => 'TRANSFERENCIA', 'actual' => $tr ? 'Inactivo (9)' : 'No está', 'esperado' => 'Activo (1)', 'revision' => false,
                        'accion' => ($tr ? 'Activar' : 'Crear') . " el PLU $p en el subplán " . COR_SUBPLAN_TRANSFERENCIA . ' (está en el Contrato Evento)'];
                }
            }
            $m = $filas[_u($sp)] ?? null;
            if (!$m) {
                if ($activo) $add('SUBPLAN', $p, implode(', ', array_keys($filas)), $sp, "Crear el PLU $p en el subplán $sp (está en " . implode(', ', array_map('ucwords', array_map('mb_strtolower', array_keys($filas)))) . ')');
                continue;
            }
            $medActivo = trim((string) $m['opcion']) === '1';
            if ($medActivo !== $activo && !($variosSiesa && !$medActivo)) {
                $add('ESTADO', $p, $medActivo ? 'Activo (1)' : 'Inactivo (9)', $activo ? 'Activo (1)' : 'Inactivo (9)',
                    ($activo ? 'Activar' : 'Inactivar') . " el PLU $p en el subplán $sp (Opción Cadena " . ($activo ? '1' : '9') . ')');
            }
            if (!$activo || !$medActivo) continue; // inactivo: solo se valida el estado
            if (!$exento && _u($m['cum']) !== $cum) $add('CUM', $p, $m['cum'], $cf, "Cambiar el CUM del PLU $p a $cf");
            $ivaMt = (int) round((float) $l['iva'] * 100); $ivaMed = _cor_num($m['iva_pct']);
            if ($ivaMed === null || (int) round($ivaMed) !== $ivaMt) $add('IVA', $p, $ivaMed === null ? null : (int) round($ivaMed) . ' %', "$ivaMt %", "Cambiar el %Iva del PLU $p en el subplán $sp a $ivaMt %");
            $precioMt = $l['precio_maximo'] === null ? null : (float) round((float) $l['precio_maximo']);
            $precioMed = _cor_num($m['precio_um_iva']);
            if ($precioMt !== null && ($precioMed === null || abs($precioMed - $precioMt) >= 1)) {
                // MEDICAR con el precio sin IVA (el valor coincide con el precio antes de IVA de la Maestra Tarifaria)
                $sinIva = $precioMed !== null && $ivaMt > 0 && abs($precioMed - round($precioMt / (1 + $ivaMt / 100))) < 2;
                $add('PRECIO', $p, $precioMed === null ? null : (string) round($precioMed), (string) $precioMt,
                    "Cambiar el Precio Unidad Minima con Iva del PLU $p en el subplán $sp a " . number_format($precioMt, 0, ',', '.')
                    . ($sinIva ? ' (MEDICAR tiene el precio sin IVA)' : ''));
            }
            foreach ([['COD_AUF', 'cod_auf', 'codigo_generico', 'Codigo generico'], ['ID_MUTUAL', 'cod_mutual', 'codigo_md', 'Codigo MD']] as [$campo, $kMt, $kMed, $nombre]) {
                $v = trim((string) $l[$kMt]);
                if ($v !== '' && _u($v) !== _u($m[$kMed])) $add($campo, $p, $m[$kMed], $v, "Cambiar el $nombre del PLU $p a $v");
            }
            if (in_array($l['tipo_producto'], ['714', '799'], true)) {
                $nuevo = $l['tipo_producto'] === '714' ? 'MEDICAMENTOS' : 'INSUMOS';
                if (_u($m['tipo_medicamento']) !== $nuevo) $add('TIPO', $p, $m['tipo_medicamento'], $nuevo, "Cambiar el Tipo Medicamento del PLU $p a $nuevo");
            }
            $inv = $invima[$cum] ?? null;
            if ($inv && !isset($inv['todos'][_cor_registro($m['registro_invima'])])) {
                $add('REGISTRO', $p, $m['registro_invima'], $inv['propuesto'], "Cambiar el Registro Invima del PLU $p a {$inv['propuesto']} (registro del CUM en INVIMA)");
            }
        }
    }
    return $out;
}

/**
 * Archivo para corregir MEDICAR: PLU + OBSERVACIÓN (formato del sistema MEDICAR), una fila por PLU con todo lo que hay que corregir.
 * Lo incluido pasa a PENDIENTE_ACTUALIZACION hasta que se recargue la Maestra MEDICAR.
 */
function cor_exportar_medicar(PDO $pdo, array $cond, array $params, int $usuarioId): array {
    $where = $cond ? ' AND ' . implode(' AND ', $cond) : '';
    $marcas = implode(', ', array_map([$pdo, 'quote'], COR_EXPORTABLES));
    // sin PLU (no está en SIESA ni en MEDICAR) no se puede cargar en MEDICAR: se crea en Crear productos
    $st = $pdo->prepare("SELECT * FROM cor_inconsistencia WHERE sistema = 'MEDICAR' AND plu <> '' AND estado IN ($marcas)$where ORDER BY plu, subplan, campo");
    $st->execute($params);
    $filas = $st->fetchAll();
    if (!$filas) throw new ErrorApi('No hay correcciones pendientes por descargar (con los filtros de la tabla).', 422);
    $porPlu = [];
    foreach ($filas as $f) $porPlu[$f['plu']][$f['accion']] = true;
    $datos = array_map(fn($p, $obs) => [$p, implode(' · ', array_keys($obs))], array_keys($porPlu), $porPlu);
    $carga = _carga_vigente($pdo, 'MAESTRA_MEDICAR');
    $id = insertar($pdo, 'cor_exportacion', ['sistema' => 'MEDICAR', 'filas' => count($filas), 'carga_id' => $carga, 'usuario_id' => $usuarioId, 'creado_en' => ahora()]);
    $nombre = 'CORRECCIONES MEDICAR ' . str_pad((string) $id, 5, '0', STR_PAD_LEFT) . ' - ' . date('Ymd') . '.xlsx';
    $ruta = rtrim(app_config()['dir_salidas'], '/\\') . '/' . $nombre;
    XlsxWriter::guardar($ruta, [['nombre' => 'Correcciones', 'encabezados' => ['PLU', 'OBSERVACION'], 'filas' => $datos]]);
    $archivos = [['nombre' => $nombre, 'filas' => count($datos)]];
    $pdo->prepare('UPDATE cor_exportacion SET archivos = ?, ruta = ? WHERE id = ?')->execute([json_encode($archivos, JSON_UNESCAPED_UNICODE), $ruta, $id]);
    $upd = $pdo->prepare("UPDATE cor_inconsistencia SET estado = 'PENDIENTE_ACTUALIZACION', exportacion_id = ?, carga_exportada = ?, exportado_en = ?, exportado_por = ?, actualizado_en = ? WHERE id = ?");
    $ahora = ahora();
    foreach (array_column($filas, 'id') as $x) $upd->execute([$id, $carga, $ahora, $usuarioId, $ahora, $x]);
    auditar($usuarioId, 'COR_EXPORTAR', 'cor_exportacion', $id, null, ['sistema' => 'MEDICAR', 'filas' => count($filas), 'archivos' => $archivos]);
    return ['id' => $id, 'filas' => count($filas), 'archivos' => $archivos];
}

/**
 * Revisa un sistema y actualiza el registro de inconsistencias: agrega las nuevas, cierra (CORREGIDO) las que ya no están y
 * pasa a NO_VALIDADO las descargadas que siguen igual con una versión nueva de la base.
 */
function cor_revisar(PDO $pdo, string $sistema, ?int $usuarioId): array {
    if (!isset(COR_SISTEMAS[$sistema])) throw new ErrorApi('Sistema no válido.', 422);
    $actual = $sistema === 'MEDICAR' ? cor_calcular_medicar($pdo) : cor_calcular_siesa($pdo);
    $carga = _carga_vigente($pdo, COR_SISTEMAS[$sistema]);
    $abiertas = [];
    $st = $pdo->prepare("SELECT * FROM cor_inconsistencia WHERE sistema = ? AND estado NOT IN ('CORREGIDO', 'APROBADO')");
    $st->execute([$sistema]);
    foreach ($st as $r) $abiertas[$r['llave']] = $r;
    $ahora = ahora();
    $n = ['nuevas' => 0, 'corregidas' => 0, 'no_validadas' => 0];
    $upd = $pdo->prepare('UPDATE cor_inconsistencia SET item = ?, contratos = ?, valor_actual = ?, valor_esperado = ?, accion = ?, estado = ?, actualizado_en = ? WHERE id = ?');
    $filas = [];
    foreach ($actual as $llave => $x) {
        $a = $abiertas[$llave] ?? null;
        unset($abiertas[$llave]);
        if (!$a) {
            $filas[] = [$sistema, $llave, $x['cum_facturar'], $x['plu'], $x['item'], $x['contratos'], $x['subplan'], $x['campo'], $x['actual'], $x['esperado'], $x['accion'],
                cor_campos($sistema)[$x['campo']][1], $x['revision'] ? 'PENDIENTE_REVISION' : 'PENDIENTE_CORRECCION', $ahora];
            $n['nuevas']++;
            continue;
        }
        $estado = $a['estado'];
        if ($x['revision']) $estado = 'PENDIENTE_REVISION';
        elseif ($estado === 'PENDIENTE_REVISION' || (string) $a['valor_esperado'] !== (string) $x['esperado']) $estado = 'PENDIENTE_CORRECCION'; // lo descargado ya no sirve
        elseif ($estado === 'PENDIENTE_ACTUALIZACION' && (int) $a['carga_exportada'] !== $carga) { $estado = 'NO_VALIDADO'; $n['no_validadas']++; }
        if ($estado !== $a['estado'] || (string) $a['valor_actual'] !== (string) $x['actual'] || (string) $a['contratos'] !== $x['contratos'] || (string) $a['accion'] !== $x['accion']) {
            $upd->execute([$x['item'], $x['contratos'], $x['actual'], $x['esperado'], $x['accion'], $estado, $ahora, $a['id']]);
        }
    }
    insertar_masivo($pdo, 'cor_inconsistencia', ['sistema', 'llave', 'cum_facturar', 'plu', 'item', 'contratos', 'subplan', 'campo', 'valor_actual', 'valor_esperado', 'accion',
        'cargue', 'estado', 'detectado_en'], $filas);
    $cerrar = $pdo->prepare("UPDATE cor_inconsistencia SET estado = 'CORREGIDO', cerrado_en = ?, actualizado_en = ? WHERE id = ?");
    foreach ($abiertas as $a) { $cerrar->execute([$ahora, $ahora, $a['id']]); $n['corregidas']++; }
    $n['abiertas'] = count($actual);
    insertar($pdo, 'cor_revision', ['sistema' => $sistema, 'carga_id' => $carga, 'nuevas' => $n['nuevas'], 'corregidas' => $n['corregidas'],
        'no_validadas' => $n['no_validadas'], 'abiertas' => $n['abiertas'], 'usuario_id' => $usuarioId, 'creado_en' => $ahora]);
    auditar($usuarioId, 'COR_REVISAR', 'cor_inconsistencia', $sistema, null, $n + ['carga' => $carga]);
    return $n;
}

/**
 * Aprueba que un CUM tenga varios PLU activos en SIESA (pedido del usuario, 2026-11-05): se guardan los PLU activos de hoy; sus
 * «Más de un PLU con el mismo CUM» pasan a APROBADO y la Maestra Tarifaria deja de marcar NT29. Si SIESA activa otro PLU, vuelve el error.
 */
function cor_aprobar_varios(PDO $pdo, string $cumFacturar, string $motivo, int $usuarioId): array {
    $motivo = trim($motivo);
    if ($motivo === '' || mb_strlen($motivo) > 300) throw new ErrorApi('Escriba el motivo (máximo 300 caracteres).', 422);
    $cum = _u($cumFacturar);
    // los PLU activos del CUM en la Maestra Tarifaria (la MT manda, 2026-11-05)
    // y los nuevos de SIESA «por definir», que al aprobar quedan activos en la Maestra Tarifaria
    $plus = []; $activar = [];
    foreach (nt_calcular($pdo, true)['filas'] as $f) {
        if (_u($f['cum_facturar']) !== $cum || !(int) $f['activo']) continue;
        foreach ($f['_plus_mt'] ?? [] as $p => $v) if ($v === 1) $plus[(string) $p] = true;
        foreach ($f['_plus_pend'] ?? [] as $p) { $plus[(string) $p] = true; $activar[] = [(int) $f['id'], (string) $p]; }
    }
    $plus = array_map('strval', array_keys($plus));
    if (count($plus) < 2) throw new ErrorApi("El CUM $cumFacturar no tiene varios PLU activos en la Maestra Tarifaria.", 422);
    $up = $pdo->prepare('UPDATE nt_item_plu SET activo = 1, actualizado_por = ?, actualizado_en = ? WHERE nt_item_id = ? AND UPPER(plu) = ?');
    foreach ($activar as [$idL, $p]) $up->execute([$usuarioId, ahora(), $idL, $p]);
    $ahora = ahora();
    $pdo->prepare("UPDATE cor_aprobacion SET activo = 0, retirado_por = ?, retirado_en = ? WHERE UPPER(cum_facturar) = ? AND campo = 'PLU_VARIOS' AND activo = 1")
        ->execute([$usuarioId, $ahora, $cum]);
    $id = insertar($pdo, 'cor_aprobacion', ['cum_facturar' => $cumFacturar, 'campo' => 'PLU_VARIOS', 'plus' => implode(',', $plus), 'motivo' => $motivo,
        'activo' => 1, 'aprobado_por' => $usuarioId, 'aprobado_en' => $ahora]);
    $st = $pdo->prepare("UPDATE cor_inconsistencia SET estado = 'APROBADO', cerrado_en = ?, actualizado_en = ?
                          WHERE sistema = 'SIESA' AND campo = 'PLU_VARIOS' AND UPPER(cum_facturar) = ? AND estado NOT IN ('CORREGIDO', 'APROBADO')");
    $st->execute([$ahora, $ahora, $cum]);
    $cerradas = $st->rowCount();
    auditar($usuarioId, 'COR_APROBAR', 'cor_aprobacion', $id, null, ['cum_facturar' => $cumFacturar, 'plus' => $plus, 'motivo' => $motivo, 'inconsistencias' => $cerradas]);
    nt_calcular($pdo, true);
    return ['id' => $id, 'plus' => $plus, 'aprobadas' => $cerradas];
}

/** Retira la aprobación de varios PLU: en la siguiente revisión vuelve el error si el CUM sigue con varios PLU activos. */
function cor_quitar_aprobacion(PDO $pdo, int $id, int $usuarioId): array {
    $st = $pdo->prepare('SELECT * FROM cor_aprobacion WHERE id = ? AND activo = 1');
    $st->execute([$id]);
    $a = $st->fetch();
    if (!$a) throw new ErrorApi('La aprobación no existe o ya fue retirada.', 404);
    $pdo->prepare('UPDATE cor_aprobacion SET activo = 0, retirado_por = ?, retirado_en = ? WHERE id = ?')->execute([$usuarioId, ahora(), $id]);
    auditar($usuarioId, 'COR_APROBACION_QUITAR', 'cor_aprobacion', $id, ['cum_facturar' => $a['cum_facturar'], 'plus' => $a['plus']], null);
    nt_calcular($pdo, true);
    return ['ok' => true, 'revision' => cor_revisar($pdo, 'SIESA', $usuarioId)];
}

/** CUM aprobados con varios PLU (vigentes). */
function cor_aprobaciones(PDO $pdo): array {
    return $pdo->query("SELECT a.id, a.cum_facturar, a.plus, a.motivo, a.aprobado_en, u.nombre AS usuario FROM cor_aprobacion a
                         LEFT JOIN seg_usuario u ON u.id = a.aprobado_por WHERE a.campo = 'PLU_VARIOS' AND a.activo = 1 ORDER BY a.cum_facturar")->fetchAll();
}

/** Resumen de un sistema: abiertas por estado y por campo, y la última revisión. */
function cor_resumen(PDO $pdo, string $sistema): array {
    $st = $pdo->prepare('SELECT estado, campo, COUNT(*) AS n FROM cor_inconsistencia WHERE sistema = ? GROUP BY estado, campo');
    $st->execute([$sistema]);
    $estados = array_fill_keys(array_keys(COR_ESTADOS), 0); $campos = [];
    foreach ($st as $r) {
        $estados[$r['estado']] += (int) $r['n'];
        if (!in_array($r['estado'], COR_CERRADOS, true)) $campos[$r['campo']] = ($campos[$r['campo']] ?? 0) + (int) $r['n'];
    }
    $st = $pdo->prepare('SELECT r.*, u.nombre AS usuario, c.version, c.origen FROM cor_revision r LEFT JOIN seg_usuario u ON u.id = r.usuario_id
                          LEFT JOIN ref_carga c ON c.id = r.carga_id WHERE r.sistema = ? ORDER BY r.id DESC LIMIT 1');
    $st->execute([$sistema]);
    $marcas = implode(', ', array_map([$pdo, 'quote'], COR_EXPORTABLES));
    $st2 = $pdo->prepare("SELECT COUNT(*) FROM cor_inconsistencia WHERE sistema = ? AND estado IN ($marcas)" . ($sistema === 'MEDICAR' ? " AND plu <> ''" : ''));
    $st2->execute([$sistema]);
    $exportables = (int) $st2->fetchColumn();
    return ['estados' => $estados, 'campos' => $campos, 'exportables' => $exportables, 'revision' => $st->fetch() ?: null,
            'carga_vigente' => _carga_vigente($pdo, COR_SISTEMAS[$sistema])];
}

/**
 * Archivos para corregir SIESA (ZIP), con las inconsistencias por corregir que cumplen los filtros de la tabla:
 *   DESCRIPCIONES_TECNICAS (f120_referencia, f123_dato, f104_id) — ID MUTUAL (código genérico auxiliar), Cód. Audifarma, tipo y registro;
 *   CREACION_ITEM_INTE (estructura de la creación) — grupo impositivo, con los datos del ítem tal como están en SIESA;
 *   PARA CORREGIR EN SIESA (a mano) — estado, PLU repetidos y CUM que no están; y el DETALLE de todo lo incluido.
 * Lo incluido pasa a PENDIENTE_ACTUALIZACION hasta que se recargue el Maestro SIESA.
 */
function cor_exportar_siesa(PDO $pdo, array $cond, array $params, int $usuarioId): array {
    $where = $cond ? ' AND ' . implode(' AND ', $cond) : '';
    $marcas = implode(', ', array_map([$pdo, 'quote'], COR_EXPORTABLES));
    $st = $pdo->prepare("SELECT * FROM cor_inconsistencia WHERE sistema = 'SIESA' AND estado IN ($marcas)$where ORDER BY cum_facturar, plu, campo");
    $st->execute($params);
    $filas = $st->fetchAll();
    if (!$filas) throw new ErrorApi('No hay correcciones pendientes por descargar (con los filtros de la tabla).', 422);
    $carga = _carga_vigente($pdo, 'MAESTRO_SIESA');
    $datoMax = (int) parametro('SIESA_DATO_MAX', '60');

    $desc = []; $iva = []; $manual = []; $detalle = [];
    foreach ($filas as $f) {
        [$nombre, $cargue, $f104] = COR_CAMPOS_SIESA[$f['campo']];
        $detalle[] = [$f['plu'], $f['item'], $f['cum_facturar'], $f['contratos'], $nombre, $f['valor_actual'], $f['valor_esperado'], $f['accion'],
            ['DESCRIPCIONES' => 'Descripciones Técnicas', 'CREACION_ITEM' => 'CREACION_ITEM', 'MANUAL' => 'A mano'][$cargue]];
        if ($cargue === 'DESCRIPCIONES') $desc[$f['plu'] . '|' . $f104] = [$f['plu'], mb_substr((string) $f['valor_esperado'], 0, $datoMax), $f104];
        elseif ($cargue === 'CREACION_ITEM') $iva[$f['plu']] = explode(' ', (string) $f['valor_esperado'])[0];
        else $manual[] = [$f['plu'], $f['item'], $f['cum_facturar'], $nombre, $f['valor_actual'], $f['valor_esperado'], $f['accion']];
    }
    $hojas = [];
    if ($desc) $hojas['descripciones'] = [CRE_ARCHIVOS['descripciones'], ['f120_referencia', 'f123_dato', 'f104_id'], array_values($desc)];
    if ($iva) {
        // mismas 20 columnas de la creación, con los datos del ítem tal como están en el Maestro SIESA y el grupo impositivo corregido
        $st = $pdo->prepare('SELECT * FROM ref_siesa_item WHERE carga_id = ? AND referencia = ? ORDER BY id');
        $filasIva = [];
        foreach ($iva as $plu => $grupo) {
            $st->execute([$carga, $plu]); $i = $st->fetch() ?: [];
            $filasIva[] = [$i['item'] ?? null, $plu, null, null, $grupo, trim((string) ($i['tipo_inventario'] ?? '')), null, '1', '1', '1', '1', '1', '1', '0',
                trim((string) ($i['unidad_inventario'] ?? '')), $i['unidad_orden'] ?? null, $i['unidad_presentacion'] ?? null, null, $i['descripcion'] ?? null, '1'];
        }
        $hojas['creacion'] = [CRE_ARCHIVOS['creacion'], ['f120_id', 'f120_referencia', 'f120_descripcion', 'f120_descripcion_corta', 'f120_id_grupo_impositivo',
            'f120_id_tipo_inv_serv', 'f120_id_segmento_costo', 'f120_ind_tipo_item', 'f120_ind_compra', 'f120_ind_venta', 'f120_ind_manufactura', 'f120_ind_lote',
            'f120_ind_lote_asignacion', 'f120_vida_util', 'f120_id_unidad_inventario', 'f120_id_unidad_orden', 'f120_factor_orden', 'f121_fecha_creacion',
            'f120_notas', 'f120_ind_exento'], $filasIva];
    }
    if ($manual) $hojas['manual'] = [['PARA CORREGIR EN SIESA (A MANO).xlsx', 'Correcciones'],
        ['PLU', 'ITEM', 'CUM FACTURAR', 'CAMPO', 'VALOR EN SIESA', 'VALOR CORRECTO', 'ACCIÓN'], $manual];
    $hojas['detalle'] = [['CORRECCIONES SIESA - DETALLE.xlsx', 'Detalle'],
        ['PLU', 'ITEM', 'CUM FACTURAR', 'CONTRATO', 'CAMPO', 'VALOR EN SIESA', 'VALOR CORRECTO', 'ACCIÓN', 'SE CARGA CON'], $detalle];

    $id = insertar($pdo, 'cor_exportacion', ['sistema' => 'SIESA', 'filas' => count($filas), 'carga_id' => $carga, 'usuario_id' => $usuarioId, 'creado_en' => ahora()]);
    $dir = rtrim(app_config()['dir_salidas'], '/\\') . '/correcciones_SIESA_' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new RuntimeException("No se pudo crear $dir");
    $archivos = [];
    $zip = new ZipArchive();
    if ($zip->open("$dir.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('No se pudo crear el ZIP.');
    foreach ($hojas as [[$nombre, $hoja], $enc, $datos]) {
        XlsxWriter::guardar("$dir/$nombre", [['nombre' => $hoja, 'encabezados' => $enc, 'filas' => $datos]]);
        $zip->addFile("$dir/$nombre", $nombre);
        $archivos[] = ['nombre' => $nombre, 'filas' => count($datos)];
    }
    $zip->close();
    $pdo->prepare('UPDATE cor_exportacion SET archivos = ?, ruta = ? WHERE id = ?')->execute([json_encode($archivos, JSON_UNESCAPED_UNICODE), "$dir.zip", $id]);
    $ids = array_column($filas, 'id');
    $upd = $pdo->prepare("UPDATE cor_inconsistencia SET estado = 'PENDIENTE_ACTUALIZACION', exportacion_id = ?, carga_exportada = ?, exportado_en = ?, exportado_por = ?, actualizado_en = ? WHERE id = ?");
    $ahora = ahora();
    foreach ($ids as $x) $upd->execute([$id, $carga, $ahora, $usuarioId, $ahora, $x]);
    auditar($usuarioId, 'COR_EXPORTAR', 'cor_exportacion', $id, null, ['sistema' => 'SIESA', 'filas' => count($filas), 'archivos' => $archivos]);
    return ['id' => $id, 'filas' => count($filas), 'archivos' => $archivos];
}

/** Descargas de archivos de corrección realizadas (para volver a bajarlas). */
function cor_exportaciones(PDO $pdo, string $sistema): array {
    $st = $pdo->prepare('SELECT e.id, e.filas, e.archivos, e.creado_en, u.nombre AS usuario, c.version FROM cor_exportacion e LEFT JOIN seg_usuario u ON u.id = e.usuario_id
                          LEFT JOIN ref_carga c ON c.id = e.carga_id WHERE e.sistema = ? AND e.ruta IS NOT NULL ORDER BY e.id DESC LIMIT 50');
    $st->execute([$sistema]);
    return array_map(fn($r) => ['archivos' => json_decode((string) $r['archivos'], true) ?: []] + $r, $st->fetchAll());
}

/** Ruta del archivo (ZIP de SIESA o Excel de MEDICAR) de una descarga de correcciones (solo dentro de la carpeta de salidas). */
function cor_ruta_zip(PDO $pdo, int $id): array {
    $st = $pdo->prepare('SELECT sistema, ruta FROM cor_exportacion WHERE id = ?');
    $st->execute([$id]);
    $e = $st->fetch();
    $base = realpath(app_config()['dir_salidas']);
    $ruta = $e && $base ? realpath((string) $e['ruta']) : false;
    if (!$ruta || !str_starts_with($ruta, $base)) throw new ErrorApi('Los archivos de esa descarga no se encuentran.', 404);
    return [$ruta, $e['sistema']];
}
