<?php
/**
 * ACTUALIZACIÓN DE CATÁLOGOS POR EXCEL (solo Administrador, sin versiones).
 *
 * Flujo: descargar plantilla (catálogo actual) -> corregir en Excel -> previsualizar -> aplicar.
 * Reglas:
 *  - Se cruza por la llave (COD GEN AUDIFARMA / CODIGO). La llave nunca se modifica.
 *  - Celda VACÍA = no se modifica el dato actual (sirve para completar vacíos sin borrar nada).
 *  - Mismas validaciones que el formulario de edición. Si hay UN error, no se aplica nada.
 *  - Filas nuevas: COD GEN con su código; laboratorio/presentación con CODIGO vacío -> consecutivo.
 *  - Cada registro modificado queda en auditoría (antes / después).
 */
require_once __DIR__ . '/xlsx_reader.php';
require_once __DIR__ . '/xlsx_writer.php';
require_once __DIR__ . '/catalogos_admin.php';

/** [columna BD, encabezado Excel, tipo, largo máx., obligatorio al crear] */
function catalogo_excel_definicion(string $tipo): array {
    $defs = [
        'molecula' => ['tabla' => 'cat_molecula', 'llave' => 'cod_gen_audifarma', 'hoja' => 'COD GEN', 'campos' => [
            ['cod_gen_audifarma',      'COD GEN AUDIFARMA',      'llave',  20,  true],
            ['descripcion_general',    'DESCRIPCION GENERICA',   'texto',  400, true],
            ['codigo_medicar',         'CODIGO MEDICAR',         'entero', 10,  true],
            ['pos_no_pos',             'POS/NO POS',             'lista',  20,  true],
            ['concentracion_agrupada', 'CONCENTRACION AGRUPADA', 'texto',  150, true],
            ['cantidad_tope',          'CANTIDAD TOPE',          'entero', 6,   false],
            ['principio_activo',       'PRINCIPIO ACTIVO',       'texto',  300, true],
            ['forma_farmaceutica',     'FORMA FARMACEUTICA',     'texto',  150, true],
            ['grupo_farmacologico',    'GRUPO FARMACOLOGICO',    'texto',  150, true],
            ['via_administracion',     'VIA DE ADMINISTRACION',  'texto',  100, true],
            ['cadena_frio',            'CADENA DE FRIO',         'lista',  10,  true],
            ['clasificacion_entrega',  'CLASIFICACION DE ENTREGA', 'lista', 40, true], // MTOS DE APLICACION / ALTO COSTO / AMBULATORIO
            ['activo',                 'ACTIVO',                 'activo', 2,   false],
        ]],
        'laboratorio' => ['tabla' => 'cat_laboratorio', 'llave' => 'codigo', 'hoja' => 'LABORATORIOS', 'campos' => [
            ['codigo', 'CODIGO',      'llave',  6,   false],
            ['nombre', 'LABORATORIO', 'texto',  250, true],
            ['activo', 'ACTIVO',      'activo', 2,   false],
        ]],
        'presentacion' => ['tabla' => 'cat_presentacion', 'llave' => 'codigo', 'hoja' => 'PRESENTACIONES', 'campos' => [
            ['codigo',                'CODIGO',                'llave',        6,   false],
            ['presentacion_agrupada', 'PRESENTACION AGRUPADA', 'solo_crear',   100, true],
            ['unidad_orden',          'UNIDAD DE ORDEN',       'unidad_orden', 10,  true],
            ['activo',                'ACTIVO',                'activo',       2,   false],
        ]],
    ];
    if (!isset($defs[$tipo])) throw new ErrorApi('Catálogo no válido para cargue por Excel.', 422);
    return $defs[$tipo];
}

function _enc(string $v): string {
    return strtr(mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $v))), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N']);
}

/** Plantilla = catálogo completo actual, listo para corregir y volver a cargar. */
function catalogo_plantilla(PDO $pdo, string $tipo, string $destino): string {
    $d = catalogo_excel_definicion($tipo);
    $cols = array_column($d['campos'], 0);
    $filas = (function () use ($pdo, $d, $cols) {
        foreach ($pdo->query('SELECT ' . implode(', ', $cols) . " FROM {$d['tabla']} ORDER BY {$d['llave']}") as $f) {
            if (isset($f['activo'])) $f['activo'] = (int) $f['activo'] ? 'SI' : 'NO';
            yield array_map(fn($v) => $v === null ? null : (string) $v, array_values($f)); // todo como texto: conserva '002'
        }
    })();
    XlsxWriter::guardar($destino, [['nombre' => $d['hoja'], 'encabezados' => array_column($d['campos'], 1), 'filas' => $filas]]);
    return 'PLANTILLA_' . $d['hoja'] . '_' . date('Ymd') . '.xlsx';
}

/**
 * Analiza el archivo y devuelve el plan de cambios. Con $aplicar = true además lo ejecuta
 * (en una sola transacción) si no hay errores.
 */
function catalogo_procesar_excel(PDO $pdo, string $tipo, string $ruta, string $nombreArchivo, int $usuarioId, bool $aplicar): array {
    $d = catalogo_excel_definicion($tipo);
    $porEncabezado = [];
    foreach ($d['campos'] as $c) $porEncabezado[_enc($c[1])] = $c;

    try { $x = new XlsxReader($ruta); } catch (Throwable) { throw new ErrorApi('El archivo no se pudo abrir como Excel (.xlsx).', 422); }
    $hoja = in_array($d['hoja'], $x->hojas(), true) ? $d['hoja'] : $x->hojas()[0];

    $errores = []; $mapa = null; $filas = [];
    $err = function (int $fila, string $col, $valor, string $motivo) use (&$errores) {
        if (count($errores) < 500) $errores[] = ['fila' => $fila, 'columna' => $col, 'valor' => (string) $valor, 'motivo' => $motivo];
    };
    foreach ($x->filas($hoja) as $num => $celdas) {
        if ($mapa === null) { // primera fila con datos = encabezados
            $mapa = [];
            foreach ($celdas as $letra => $v) {
                $e = _enc($v);
                if (!isset($porEncabezado[$e])) { $err($num, $letra, $v, 'Columna no reconocida. Use la plantilla descargada.'); continue; }
                $mapa[$letra] = $porEncabezado[$e];
            }
            if (!in_array($d['llave'], array_column($mapa, 0), true)) {
                throw new ErrorApi('Falta la columna llave ' . $d['campos'][0][1] . '. Use la plantilla descargada.', 422);
            }
            continue;
        }
        $f = [];
        foreach ($mapa as $letra => $c) if (isset($celdas[$letra])) $f[$c[0]] = trim((string) $celdas[$letra]);
        if (array_filter($f, fn($v) => $v !== '')) $filas[$num] = $f;
    }
    if ($mapa === null) throw new ErrorApi('El archivo está vacío.', 422);

    // Estado actual completo en memoria: una consulta, no una por fila.
    $cols = array_column($d['campos'], 0);
    $actual = [];
    foreach ($pdo->query('SELECT id, ' . implode(', ', $cols) . " FROM {$d['tabla']}") as $r) $actual[mb_strtoupper((string) $r[$d['llave']])] = $r;
    $listas = MOLECULA_LISTAS;
    $envases = array_map('mb_strtoupper', $pdo->query("SELECT valor FROM cat_lista_valor WHERE lista = 'ENVASE' AND activo = 1")->fetchAll(PDO::FETCH_COLUMN));

    $cambios = []; $nuevos = []; $sinCambios = 0; $vistos = []; $porCorregir = []; $avisos = [];
    $siguiente = in_array($tipo, ['laboratorio', 'presentacion'], true) ? (int) siguiente_codigo($pdo, $tipo) : 0;

    // COD GEN: para distinguir un registro NUEVO de una CORRECCIÓN DE CÓDIGO (p.ej. ',' -> código real)
    // se cruza también por código Medicar, que es prácticamente único.
    $clavesArchivo = []; $porMedicar = []; $corregidos = [];
    if ($tipo === 'molecula') {
        foreach ($filas as $f) $clavesArchivo[mb_strtoupper(codigo_excel($f['cod_gen_audifarma'] ?? ''))] = true;
        foreach ($actual as $k => $r) $porMedicar[(string) $r['codigo_medicar']][] = $k;
    }

    foreach ($filas as $num => $f) {
        $llave = mb_strtoupper(codigo_excel($f[$d['llave']] ?? ''));
        if ($llave === '' && $tipo === 'molecula') { $err($num, 'COD GEN AUDIFARMA', '', 'Falta el código genérico Audifarma.'); continue; }
        if ($llave !== '' && isset($vistos[$llave])) { $err($num, $d['campos'][0][1], $llave, "Código repetido en el archivo (fila {$vistos[$llave]})."); continue; }
        if ($llave !== '') $vistos[$llave] = $num;
        $existente = $llave !== '' ? ($actual[$llave] ?? null) : null;
        if ($llave !== '' && !$existente && $tipo !== 'molecula') { $err($num, 'CODIGO', $llave, 'El código no existe. Para crear uno nuevo deje CODIGO vacío (se asigna el consecutivo).'); continue; }
        if ($tipo === 'molecula' && !$existente && !preg_match('/^[0-9A-Z]{1,20}$/', $llave)) { $err($num, 'COD GEN AUDIFARMA', $llave, 'Código inválido: solo letras y números.'); continue; }

        $correccion = null;
        if ($tipo === 'molecula' && !$existente) {
            $medicar = codigo_excel($f['codigo_medicar'] ?? '');
            $mismos = $porMedicar[$medicar] ?? [];
            // Candidatos: registros con el mismo código Medicar cuyo código genérico ya NO está en el archivo.
            $candidatos = array_values(array_filter($mismos, fn($k) => !isset($clavesArchivo[$k]) && !isset($corregidos[$k])));
            if (count($candidatos) > 1) {
                $err($num, 'CODIGO MEDICAR', $medicar, 'Ambiguo: el código Medicar corresponde a varios registros que no están en el archivo (' . implode(', ', $candidatos) . '). Corrija el código en la fila del registro correcto.');
                continue;
            }
            if (count($candidatos) === 1) {
                $existente = $actual[$candidatos[0]];
                $corregidos[$candidatos[0]] = $num;
                $correccion = ['antes' => $existente['cod_gen_audifarma'], 'despues' => $llave];
            } elseif ($mismos) {
                // el código Medicar no se puede repetir (decisión del usuario, 2026-10-14)
                $err($num, 'CODIGO MEDICAR', $medicar, 'El código Medicar ya lo tiene el COD GEN ' . implode(', ', $mismos) . ': no se puede repetir.');
                continue;
            }
        }

        $nuevo = []; $filaOk = true;
        foreach ($d['campos'] as [$col, $enc, $t, $max, $oblig]) {
            if ($t === 'llave' || !array_key_exists($col, $f) || $f[$col] === '') continue; // vacío = no se modifica
            $v = $f[$col];
            // Celda igual al dato actual (sin distinguir mayúsculas ni espacios) = no es un cambio: no se
            // valida ni se reescribe. Si ese dato actual es irregular, se reporta como "dato a corregir".
            $igualAlActual = false;
            if ($existente && $t !== 'solo_crear') {
                $actualTxt = $t === 'activo' ? ((int) $existente[$col] ? 'SI' : 'NO') : (string) $existente[$col];
                $celdaTxt  = $t === 'entero' ? codigo_excel($v) : $v;
                $igualAlActual = normalizar_texto($actualTxt) === normalizar_texto($celdaTxt);
            }
            $motivo = null;
            switch ($t) {
                case 'texto':
                    $v = normalizar_texto($v);
                    if (mb_strlen($v) > $max) $motivo = "Supera $max caracteres.";
                    // Barrera: '.', '-', '/' no son datos. Si no aplica, escribir NO APLICA.
                    elseif (!preg_match('/[\p{L}\p{N}]/u', $v)) $motivo = 'Valor sin letras ni números. Si el dato no aplica, escriba NO APLICA.';
                    break;
                case 'entero': $v = codigo_excel($v); if (!preg_match('/^\d{1,' . $max . '}$/', $v)) $motivo = 'Debe ser un número entero.'; break;
                case 'lista': $v = normalizar_lista($v); if (!in_array($v, $listas[$col], true)) $motivo = 'Valor no permitido. Use: ' . implode(', ', $listas[$col]); break;
                case 'activo': $v = normalizar_texto($v); if (!in_array($v, ['SI', 'NO'], true)) $motivo = 'Use SI o NO.'; $v = $v === 'SI' ? 1 : 0; break;
                case 'unidad_orden': $v = normalizar_texto($v); if (!preg_match('/^[A-Z0-9]{2,10}$/', $v)) $motivo = '2 a 10 letras o números, sin espacios.'; break;
                case 'solo_crear':
                    $v = normalizar_texto($v);
                    if ($existente) {
                        if ($v !== mb_strtoupper((string) $existente[$col])) { $err($num, $enc, $v, "No se modifica en un código existente (actual: {$existente[$col]})."); $filaOk = false; }
                        continue 2;
                    }
                    if (!preg_match('/^(.+) X (\d{1,6})$/', $v, $m) || !in_array($m[1], $envases, true)) $motivo = 'Formato ENVASE X CANTIDAD con un envase de la lista (ej. CAJA X 50).';
                    break;
            }
            if ($igualAlActual) {
                if ($motivo && count($porCorregir) < 500) $porCorregir[] = ['fila' => $num, 'codigo' => $existente[$d['llave']], 'columna' => $enc, 'valor' => $f[$col], 'motivo' => $motivo];
                continue;
            }
            if ($motivo) { $err($num, $enc, $f[$col], $motivo); $filaOk = false; continue; }
            $nuevo[$col] = $v;
        }
        if (!$filaOk) continue;
        // COD GEN existente al que se le cambia el código Medicar por uno que ya tiene otro: error
        if ($tipo === 'molecula' && $existente && isset($nuevo['codigo_medicar']) && (string) $nuevo['codigo_medicar'] !== (string) $existente['codigo_medicar']) {
            $otros = array_values(array_filter($porMedicar[(string) $nuevo['codigo_medicar']] ?? [], fn($k) => $k !== mb_strtoupper($existente['cod_gen_audifarma'])));
            if ($otros) { $err($num, 'CODIGO MEDICAR', $nuevo['codigo_medicar'], 'El código Medicar ya lo tiene el COD GEN ' . implode(', ', $otros) . ': no se puede repetir.'); continue; }
        }

        if ($existente) {
            $dif = $correccion ? ['cod_gen_audifarma' => $correccion] : [];
            foreach ($nuevo as $col => $v) if ((string) $existente[$col] !== (string) $v) $dif[$col] = ['antes' => $existente[$col], 'despues' => $v];
            if (!$dif) { $sinCambios++; continue; }
            $cambios[] = ['fila' => $num, 'llave' => $correccion ? "{$correccion['antes']} → {$correccion['despues']}" : $existente[$d['llave']],
                          'id' => (int) $existente['id'], 'campos' => $dif, 'correccion_codigo' => (bool) $correccion];
        } else {
            foreach ($d['campos'] as [$col, $enc, $t, , $oblig]) {
                if ($oblig && $t !== 'llave' && !isset($nuevo[$col])) { $err($num, $enc, '', 'Obligatorio para un registro nuevo.'); $filaOk = false; }
            }
            if (!$filaOk) continue;
            $llaveNueva = $tipo === 'molecula' ? $llave : (string) $siguiente++;
            $nuevos[] = ['fila' => $num, 'llave' => $llaveNueva, 'campos' => $nuevo + ['activo' => $nuevo['activo'] ?? 1]];
        }
    }

    // Unicidad del resultado final (nombre de laboratorio, presentación agrupada) contra la base y el archivo.
    $unico = ['laboratorio' => ['nombre', 'LABORATORIO'], 'presentacion' => ['presentacion_agrupada', 'PRESENTACION AGRUPADA']][$tipo] ?? null;
    if ($unico) {
        [$col, $enc] = $unico;
        $final = [];
        foreach ($actual as $r) $final[mb_strtoupper(trim((string) $r[$col]))] = $r[$d['llave']];
        foreach ($cambios as $c) if (isset($c['campos'][$col])) {
            unset($final[mb_strtoupper(trim((string) $c['campos'][$col]['antes']))]);
        }
        foreach ([...$cambios, ...$nuevos] as $c) {
            $v = isset($c['campos'][$col]['despues']) ? $c['campos'][$col]['despues'] : ($c['campos'][$col] ?? null);
            if (!is_string($v)) continue;
            if (isset($final[$v]) && $final[$v] !== $c['llave']) $err($c['fila'], $enc, $v, "Ya lo usa el código {$final[$v]}.");
            $final[$v] = $c['llave'];
        }
    }

    $resultado = [
        'archivo' => $nombreArchivo, 'hoja' => $hoja,
        'resumen' => ['filas' => count($filas), 'sin_cambios' => $sinCambios, 'actualizar' => count($cambios), 'nuevos' => count($nuevos),
                      'errores' => count($errores), 'por_corregir' => count($porCorregir),
                      'correcciones_codigo' => count(array_filter($cambios, fn($c) => $c['correccion_codigo']))],
        'cambios' => array_slice($cambios, 0, 500), 'nuevos' => array_slice($nuevos, 0, 200), 'errores' => $errores,
        'por_corregir' => $porCorregir, 'avisos' => $avisos, 'aplicado' => false,
    ];
    if (!$aplicar) return $resultado;
    if ($errores) throw new ErrorApi('El archivo tiene errores: corríjalos y vuelva a cargarlo. No se aplicó ningún cambio.', 422);
    if (!$cambios && !$nuevos) throw new ErrorApi('No hay cambios para aplicar.', 422);

    $pdo->beginTransaction();
    try {
        foreach ($cambios as $c) {
            $st = $pdo->prepare("SELECT * FROM {$d['tabla']} WHERE id = ?");
            $st->execute([$c['id']]);
            $antes = $st->fetch();
            $valores = array_map(fn($x) => $x['despues'], $c['campos']);
            if (array_key_exists('actualizado_en', $antes)) $valores['actualizado_en'] = ahora();
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($valores)));
            $pdo->prepare("UPDATE {$d['tabla']} SET $set WHERE id = ?")->execute([...array_values($valores), $c['id']]);
            auditar($usuarioId, 'CATALOGO_EXCEL_EDITAR', $d['tabla'], $c['id'], $antes, $valores);
        }
        foreach ($nuevos as $n) {
            $fila = [$d['llave'] => $n['llave']] + $n['campos'] + ['actualizado_en' => ahora()];
            $id = insertar($pdo, $d['tabla'], $fila);
            auditar($usuarioId, 'CATALOGO_EXCEL_CREAR', $d['tabla'], $id, null, $fila);
        }
        auditar($usuarioId, 'CATALOGO_EXCEL_CARGUE', $d['tabla'], null, null, [
            'archivo' => $nombreArchivo, 'hash' => hash_file('sha256', $ruta), 'actualizados' => count($cambios), 'nuevos' => count($nuevos),
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    $resultado['aplicado'] = true;
    return $resultado;
}
