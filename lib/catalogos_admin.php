<?php
/**
 * EDICIÓN DE CATÁLOGOS (solo Administrador: permiso CATALOGO.EDITAR).
 *
 * Barreras:
 *  - Los CÓDIGOS no se editan después de creados: hacen parte del código de producto
 *    (COD_MOLECULA _ PRESENTACION _ LABORATORIO). Cambiarlos rompería productos ya creados.
 *  - No se borra nada: se desactiva (un registro inactivo no se podrá usar al validar).
 *  - Nombres únicos sin importar mayúsculas ni espacios repetidos.
 *  - Todo cambio queda en auditoría con el valor anterior y el nuevo.
 */
require_once __DIR__ . '/datos.php';

/** Mayúsculas, sin espacios al inicio/fin ni repetidos (evita duplicados como 'IPCA ...\t'). */
function normalizar_texto(?string $v): string {
    return mb_strtoupper(trim(preg_replace('/\s+/u', ' ', (string) $v)));
}

function _texto_obligatorio(array $in, string $campo, string $etiqueta, int $max): string {
    $v = normalizar_texto($in[$campo] ?? '');
    if ($v === '') throw new ErrorApi("$etiqueta es obligatorio.", 422);
    if (mb_strlen($v) > $max) throw new ErrorApi("$etiqueta supera $max caracteres.", 422);
    if (!preg_match('/[\p{L}\p{N}]/u', $v)) throw new ErrorApi("$etiqueta no puede ser solo signos ('.', '-'...). Si no aplica, escriba NO APLICA.", 422);
    return $v;
}

function _fila(PDO $pdo, string $tabla, int $id): array {
    $st = $pdo->prepare("SELECT * FROM $tabla WHERE id = ?");
    $st->execute([$id]);
    $f = $st->fetch();
    if (!$f) throw new ErrorApi('Registro no encontrado.', 404);
    return $f;
}

function _existe_otro(PDO $pdo, string $tabla, string $expr, string $valor, int $excluirId): ?array {
    $st = $pdo->prepare("SELECT * FROM $tabla WHERE $expr = ? AND id <> ? LIMIT 1");
    $st->execute([$valor, $excluirId]);
    return $st->fetch() ?: null;
}

/** Siguiente código numérico libre (los códigos de laboratorio y presentación son consecutivos). */
function siguiente_codigo(PDO $pdo, string $tipo): string {
    $tabla = ['laboratorio' => 'cat_laboratorio', 'presentacion' => 'cat_presentacion'][$tipo] ?? null;
    if (!$tabla) throw new ErrorApi('Tipo no válido.', 422);
    $max = 0;
    foreach ($pdo->query("SELECT codigo FROM $tabla") as $r) $max = max($max, (int) $r['codigo']); // sin CAST: MySQL no acepta AS INTEGER
    return (string) ($max + 1);
}

/**
 * Siguiente código Medicar (COD_GEN_SIESA) libre para un COD GEN nuevo (decisión del usuario, 2026-10-11: es
 * automático, no se digita). Es el mayor + 1 entre los códigos del catálogo COD GEN y los códigos de molécula de la
 * Maestra MEDICAR (todas las cargas), para no repetir uno que ya exista en Medicar.
 */
/** Códigos Medicar que hoy tienen varios COD GEN (el Administrador debe corregirlos): [[código, [COD AUF…]]]. */
function codigos_medicar_repetidos(PDO $pdo): array {
    $out = [];
    foreach ($pdo->query("SELECT codigo_medicar, cod_gen_audifarma FROM cat_molecula WHERE codigo_medicar IS NOT NULL AND codigo_medicar <> '' ORDER BY codigo_medicar, cod_gen_audifarma") as $r)
        $out[(string) $r['codigo_medicar']][] = $r['cod_gen_audifarma'];
    return array_values(array_map(fn($c, $cs) => ['codigo' => $c, 'cod_gen' => $cs], array_keys($out = array_filter($out, fn($x) => count($x) > 1)), $out));
}

function siguiente_codigo_medicar(PDO $pdo): string {
    $max = 0;
    foreach ($pdo->query('SELECT DISTINCT codigo_medicar FROM cat_molecula')->fetchAll(PDO::FETCH_COLUMN) as $v) if (preg_match('/^\d{1,10}$/', trim((string) $v))) $max = max($max, (int) $v);
    foreach ($pdo->query('SELECT DISTINCT codigo_molecula FROM ref_medicar')->fetchAll(PDO::FETCH_COLUMN) as $v) if (preg_match('/^\d{1,10}$/', trim((string) $v))) $max = max($max, (int) $v);
    return (string) ($max + 1);
}

// ------------------------------------------------------------------ laboratorios
/** Clave de comparación de un laboratorio: sin puntos, espacios ni signos («PROCAPS S.A.» = «PROCAPS SA»). */
function laboratorio_clave(?string $nombre): string {
    return preg_replace('/[^A-Z0-9]/', '', strtr(mb_strtoupper((string) $nombre), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N']));
}

/** Laboratorio ACTIVO con el mismo nombre (sin importar puntos ni espacios), o null. */
function laboratorio_activo_por_nombre(PDO $pdo, ?string $nombre): ?array {
    $clave = laboratorio_clave($nombre);
    if ($clave === '') return null;
    foreach ($pdo->query('SELECT id, codigo, nombre FROM cat_laboratorio WHERE activo = 1') as $l) if (laboratorio_clave($l['nombre']) === $clave) return $l;
    return null;
}

/**
 * Depura los laboratorios repetidos (mismo nombre salvo puntos o espacios): queda el código que más usa el Maestro
 * SIESA (último segmento de la referencia); con empate, el código más antiguo. Los demás se inactivan (no se
 * borran: sus códigos existen en productos de SIESA). Queda en auditoría. Decisión del usuario (2026-10-11).
 */
function laboratorio_depurar(PDO $pdo, ?int $usuarioId): array {
    $uso = [];
    $cs = (int) $pdo->query("SELECT MAX(id) FROM ref_carga WHERE tipo_base = 'MAESTRO_SIESA' AND vigente = 1")->fetchColumn();
    if ($cs) foreach ($pdo->query("SELECT referencia FROM ref_siesa_item WHERE carga_id = $cs ORDER BY id") as $r) {
        $p = explode('_', (string) $r['referencia']);
        if (count($p) >= 3) $uso[$p[2]] = ($uso[$p[2]] ?? 0) + 1;
    }
    $grupos = [];
    foreach ($pdo->query('SELECT id, codigo, nombre FROM cat_laboratorio WHERE activo = 1') as $l) $grupos[laboratorio_clave($l['nombre'])][] = $l;
    $res = [];
    $up = $pdo->prepare('UPDATE cat_laboratorio SET activo = 0, actualizado_en = ? WHERE id = ?');
    foreach ($grupos as $g) {
        if (count($g) < 2) continue;
        // más uso en SIESA primero; con empate, el código más antiguo (menor)
        usort($g, fn($a, $b) => [($uso[$b['codigo']] ?? 0), (int) $a['codigo']] <=> [($uso[$a['codigo']] ?? 0), (int) $b['codigo']]);
        $queda = array_shift($g);
        foreach ($g as $x) {
            $up->execute([ahora(), $x['id']]);
            auditar($usuarioId, 'LABORATORIO_DEPURAR', 'cat_laboratorio', $x['id'], ['activo' => 1, 'codigo' => $x['codigo'], 'nombre' => $x['nombre']],
                ['activo' => 0, 'queda' => "{$queda['codigo']} {$queda['nombre']}", 'uso_siesa' => [$queda['codigo'] => $uso[$queda['codigo']] ?? 0, $x['codigo'] => $uso[$x['codigo']] ?? 0]]);
            $res[] = ['queda' => $queda, 'inactivo' => $x];
        }
    }
    return $res;
}

function laboratorio_guardar(PDO $pdo, array $in, int $usuarioId): array {
    $id = (int) ($in['id'] ?? 0);
    $nombre = _texto_obligatorio($in, 'nombre', 'El nombre del laboratorio', 250);
    $activo = !empty($in['activo']) ? 1 : 0;
    if ($o = _existe_otro($pdo, 'cat_laboratorio', 'UPPER(TRIM(nombre))', $nombre, $id)) {
        throw new ErrorApi("Ya existe el laboratorio '{$o['nombre']}' con código {$o['codigo']}.", 409);
    }
    // barrera: no se crean repetidos que solo cambian en puntos o espacios (PROCAPS S.A / PROCAPS S.A.)
    if (($o = laboratorio_activo_por_nombre($pdo, $nombre)) && (int) $o['id'] !== $id) {
        throw new ErrorApi("Ya existe el laboratorio '{$o['nombre']}' con código {$o['codigo']} (solo cambian puntos o espacios).", 409);
    }
    if ($id) {
        $antes = _fila($pdo, 'cat_laboratorio', $id);
        $pdo->prepare('UPDATE cat_laboratorio SET nombre = ?, activo = ?, actualizado_en = ? WHERE id = ?')->execute([$nombre, $activo, ahora(), $id]);
    } else {
        $antes = null;
        $codigo = trim((string) ($in['codigo'] ?? '')) ?: siguiente_codigo($pdo, 'laboratorio');
        if (!preg_match('/^\d{1,6}$/', $codigo)) throw new ErrorApi('El código del laboratorio debe ser numérico.', 422);
        if ($o = _existe_otro($pdo, 'cat_laboratorio', 'codigo', $codigo, 0)) throw new ErrorApi("El código $codigo ya es de '{$o['nombre']}'.", 409);
        $id = insertar($pdo, 'cat_laboratorio', ['nombre' => $nombre, 'codigo' => $codigo, 'activo' => 1, 'actualizado_en' => ahora()]);
    }
    $despues = _fila($pdo, 'cat_laboratorio', $id);
    auditar($usuarioId, $antes ? 'LABORATORIO_EDITAR' : 'LABORATORIO_CREAR', 'cat_laboratorio', $id, $antes, $despues);
    return $despues;
}

// ------------------------------------------------------------------ presentaciones
/**
 * La presentación agrupada se arma igual que en el Excel (columna AB): ENVASE + " X " + cantidad,
 * con el envase tomado de la lista oficial (LISTAS!H).
 */
function presentacion_guardar(PDO $pdo, array $in, int $usuarioId): array {
    $id = (int) ($in['id'] ?? 0);
    $unidad = normalizar_texto($in['unidad_orden'] ?? '');
    if (!preg_match('/^[A-Z0-9]{2,10}$/', $unidad)) throw new ErrorApi('La unidad de orden debe tener de 2 a 10 letras o números, sin espacios (ej. CX50).', 422);
    $activo = !empty($in['activo']) ? 1 : 0;
    $aviso = null;

    if ($id) {
        $antes = _fila($pdo, 'cat_presentacion', $id);
        $pdo->prepare('UPDATE cat_presentacion SET unidad_orden = ?, activo = ?, actualizado_en = ? WHERE id = ?')->execute([$unidad, $activo, ahora(), $id]);
    } else {
        $antes = null;
        $envase = normalizar_texto($in['envase'] ?? '');
        $st = $pdo->prepare("SELECT COUNT(*) FROM cat_lista_valor WHERE lista = 'ENVASE' AND UPPER(valor) = ? AND activo = 1");
        $st->execute([$envase]);
        if (!(int) $st->fetchColumn()) throw new ErrorApi('Seleccione un envase de la lista.', 422);
        $cantidad = trim((string) ($in['cantidad'] ?? ''));
        if (!preg_match('/^\d{1,6}$/', $cantidad) || (int) $cantidad < 1) throw new ErrorApi('La cantidad debe ser un número entero mayor a 0.', 422);
        $agrupada = $envase . ' X ' . (int) $cantidad;
        if ($o = _existe_otro($pdo, 'cat_presentacion', 'UPPER(presentacion_agrupada)', $agrupada, 0)) {
            throw new ErrorApi("La presentación '$agrupada' ya existe con código {$o['codigo']}.", 409);
        }
        $codigo = siguiente_codigo($pdo, 'presentacion');
        $id = insertar($pdo, 'cat_presentacion', ['presentacion_agrupada' => $agrupada, 'codigo' => $codigo, 'unidad_orden' => $unidad, 'activo' => 1, 'actualizado_en' => ahora()]);
    }
    if ($o = _existe_otro($pdo, 'cat_presentacion', 'unidad_orden', $unidad, $id)) {
        $aviso = "La unidad de orden $unidad también la usa '{$o['presentacion_agrupada']}'. Es permitido en SIESA, pero verifique que sea correcto.";
    }
    $despues = _fila($pdo, 'cat_presentacion', $id);
    auditar($usuarioId, $antes ? 'PRESENTACION_EDITAR' : 'PRESENTACION_CREAR', 'cat_presentacion', $id, $antes, $despues);
    return ['registro' => $despues, 'aviso' => $aviso];
}

// ------------------------------------------------------------------ COD GEN (moléculas)
const MOLECULA_LISTAS = [
    'pos_no_pos'  => ['POS', 'NO POS', 'ASIGNAR'],
    'cadena_frio' => ['SI', 'NO', 'NO APLICA'],
    // Clasificación de entrega (2026-11-05): obligatoria al crear; los COD GEN que ya existían pueden quedar vacíos hasta asignarla
    'clasificacion_entrega' => ['MTOS DE APLICACION', 'ALTO COSTO', 'AMBULATORIO'],
];
/** Valor de lista comparable: mayúsculas, sin tildes ni espacios repetidos («Mtos de Aplicación» = MTOS DE APLICACION). */
function normalizar_lista(?string $v): string {
    return strtr(normalizar_texto($v), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']);
}

function molecula_guardar(PDO $pdo, array $in, int $usuarioId): array {
    $id = (int) ($in['id'] ?? 0);
    $datos = [
        'descripcion_general'    => _texto_obligatorio($in, 'descripcion_general', 'La descripción genérica', 400),
        'codigo_medicar'         => trim((string) ($in['codigo_medicar'] ?? '')),
        'concentracion_agrupada' => _texto_obligatorio($in, 'concentracion_agrupada', 'La concentración agrupada', 150),
        'principio_activo'       => _texto_obligatorio($in, 'principio_activo', 'El principio activo', 300),
        'forma_farmaceutica'     => _texto_obligatorio($in, 'forma_farmaceutica', 'La forma farmacéutica', 150),
        'grupo_farmacologico'    => _texto_obligatorio($in, 'grupo_farmacologico', 'El grupo farmacológico', 150),
        'via_administracion'     => _texto_obligatorio($in, 'via_administracion', 'La vía de administración', 100),
        'pos_no_pos'             => normalizar_texto($in['pos_no_pos'] ?? ''),
        'cadena_frio'            => normalizar_texto($in['cadena_frio'] ?? ''),
        'clasificacion_entrega'  => normalizar_lista($in['clasificacion_entrega'] ?? ''),
        'cantidad_tope'          => trim((string) ($in['cantidad_tope'] ?? '')),
        'activo'                 => !empty($in['activo']) ? 1 : 0,
        'actualizado_en'         => ahora(),
    ];
    // COD GEN nuevo: el código Medicar se asigna solo (siguiente consecutivo libre)
    if (!$id) $datos['codigo_medicar'] = siguiente_codigo_medicar($pdo);
    // El código Medicar no se puede repetir (decisión del usuario, 2026-10-14): si otro COD GEN ya lo tiene, error
    $st = $pdo->prepare('SELECT cod_gen_audifarma FROM cat_molecula WHERE codigo_medicar = ? AND id <> ? LIMIT 5');
    $st->execute([trim((string) $datos['codigo_medicar']), $id]);
    if ($dup = $st->fetchAll(PDO::FETCH_COLUMN)) {
        throw new ErrorApi("El código Medicar {$datos['codigo_medicar']} ya lo tiene el COD GEN " . implode(', ', $dup) . ': no se puede repetir. Use otro código Medicar o corrija el registro que lo tiene.', 409);
    }
    // El código Medicar es el COD_MOLECULA del código de producto: debe ser numérico.
    if (!preg_match('/^\d{1,10}$/', $datos['codigo_medicar'])) throw new ErrorApi('El código Medicar (COD_GEN_SIESA) es obligatorio y numérico.', 422);
    foreach (MOLECULA_LISTAS as $campo => $validos) {
        if ($campo === 'clasificacion_entrega' && $datos[$campo] === '') {
            if (!$id) throw new ErrorApi('Asigne la clasificación de entrega: ' . implode(', ', $validos) . '.', 422);
            $datos[$campo] = null; // COD GEN que ya existía sin clasificación: puede seguir vacío
            continue;
        }
        if (!in_array($datos[$campo], $validos, true)) throw new ErrorApi("Valor no permitido en $campo. Use: " . implode(', ', $validos), 422);
    }
    if ($datos['cantidad_tope'] !== '' && !preg_match('/^\d{1,6}$/', $datos['cantidad_tope'])) throw new ErrorApi('La cantidad tope debe ser un número entero.', 422);

    if ($id) {
        $antes = _fila($pdo, 'cat_molecula', $id);
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($datos)));
        $pdo->prepare("UPDATE cat_molecula SET $set WHERE id = ?")->execute([...array_values($datos), $id]);
    } else {
        $antes = null;
        $cod = strtoupper(trim((string) ($in['cod_gen_audifarma'] ?? '')));
        if (!preg_match('/^[0-9A-Z]{1,20}$/', $cod)) throw new ErrorApi('El código genérico Audifarma es obligatorio (letras y números, ej. 4039M).', 422);
        if ($o = _existe_otro($pdo, 'cat_molecula', 'UPPER(cod_gen_audifarma)', $cod, 0)) {
            throw new ErrorApi("El código $cod ya existe: {$o['descripcion_general']}.", 409);
        }
        $id = insertar($pdo, 'cat_molecula', ['cod_gen_audifarma' => $cod] + $datos);
    }
    // si dos COD GEN nuevos tomaron el mismo código a la vez, el segundo se rechaza
    $st = $pdo->prepare('SELECT COUNT(*) FROM cat_molecula WHERE codigo_medicar = ?');
    $st->execute([$datos['codigo_medicar']]);
    if ((int) $st->fetchColumn() > 1) {
        if (!$antes) $pdo->prepare('DELETE FROM cat_molecula WHERE id = ?')->execute([$id]);
        throw new ErrorApi("El código Medicar {$datos['codigo_medicar']} quedó repetido: vuelva a intentarlo.", 409);
    }
    $aviso = null;

    $despues = _fila($pdo, 'cat_molecula', $id);
    auditar($usuarioId, $antes ? 'MOLECULA_EDITAR' : 'MOLECULA_CREAR', 'cat_molecula', $id, $antes, $despues);
    return ['registro' => $despues, 'aviso' => $aviso];
}

// ------------------------------------------------------------------ demás catálogos (+ Nuevo / Editar)
/**
 * Guarda un registro de: grupo_imp, tipo_inv, rips, ium, campo_tec.
 * Con $in['editar'] = true se modifica el existente (la llave no cambia); si no, se crea.
 */
function catalogo_guardar(PDO $pdo, string $tipo, array $in, int $usuarioId): array {
    $editar = !empty($in['editar']);
    $activo = !empty($in['activo']) ? 1 : 0;
    $buscar = function (string $sql, array $p) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetch() ?: null; };

    switch ($tipo) {
        case 'grupo_imp': // grupo impositivo SIESA (0016 / 0027)
            $tabla = 'cat_grupo_impositivo'; $llave = ['codigo' => trim((string) ($in['codigo'] ?? ''))];
            if (!preg_match('/^\d{4}$/', $llave['codigo'])) throw new ErrorApi('El código del grupo impositivo son 4 dígitos (ej. 0016).', 422);
            $iva = trim((string) ($in['iva_medicar'] ?? ''));
            if (!preg_match('/^\d{1,3}$/', $iva) || (int) $iva > 100) throw new ErrorApi('El IVA Medicar debe ser un porcentaje entre 0 y 100.', 422);
            $datos = ['descripcion' => _texto_obligatorio($in, 'descripcion', 'La descripción', 150), 'iva_medicar' => (int) $iva, 'activo' => $editar ? $activo : 1];
            if (!$editar) {
                $datos['etiqueta_iva'] = _texto_obligatorio($in, 'etiqueta_iva', 'La etiqueta de IVA', 20);
                if ($o = $buscar('SELECT codigo FROM cat_grupo_impositivo WHERE UPPER(etiqueta_iva) = ?', [$datos['etiqueta_iva']])) throw new ErrorApi("La etiqueta '{$datos['etiqueta_iva']}' ya la usa el grupo {$o['codigo']}.", 409);
            }
            break;

        case 'tipo_inv': // tipo de inventario SIESA según el tipo de medicamento
            $tabla = 'cat_tipo_inventario'; $llave = ['codigo' => normalizar_texto($in['codigo'] ?? '')];
            if (!preg_match('/^[A-Z0-9]{3,20}$/', $llave['codigo'])) throw new ErrorApi('Código de tipo de inventario no válido (ej. IN350501).', 422);
            $datos = ['descripcion' => _texto_obligatorio($in, 'descripcion', 'La descripción', 150), 'activo' => $editar ? $activo : 1];
            if (!$editar) {
                $tm = normalizar_texto($in['tipo_medicamento'] ?? '');
                if (!$buscar("SELECT 1 FROM cat_lista_valor WHERE lista = 'TIPO_MEDICAMENTO' AND UPPER(valor) = ? AND activo = 1", [$tm])) throw new ErrorApi('Seleccione un tipo de medicamento del catálogo "Tipo de medicamento".', 422);
                if ($o = $buscar('SELECT codigo FROM cat_tipo_inventario WHERE UPPER(tipo_medicamento) = ?', [$tm])) throw new ErrorApi("El tipo de medicamento $tm ya tiene el tipo de inventario {$o['codigo']}.", 409);
                $datos['tipo_medicamento'] = $tm;
            }
            break;

        case 'rips': // tablas RIPS: FFM (forma farmacéutica), UMM (unidad de medida), UPR (unidad de presentación)
            $tabla = 'cat_rips';
            $t = normalizar_texto($in['tabla'] ?? ''); $cod = normalizar_texto($in['codigo'] ?? '');
            if (!in_array($t, ['FFM', 'UMM', 'UPR'], true)) throw new ErrorApi('Tabla RIPS no válida (FFM, UMM o UPR).', 422);
            if (!preg_match('/^[A-Z0-9]{1,30}$/', $cod)) throw new ErrorApi('Código RIPS no válido.', 422);
            $llave = ['tabla' => $t, 'codigo' => $cod];
            $datos = ['nombre' => _texto_obligatorio($in, 'nombre', 'El nombre', 300), 'habilitado' => $editar ? $activo : 1];
            break;

        case 'ium':
            $tabla = 'cat_ium'; $llave = ['codigo' => normalizar_texto($in['codigo'] ?? '')];
            if (!preg_match('/^[A-Z0-9]{5,20}$/', $llave['codigo'])) throw new ErrorApi('Código IUM no válido (letras y números, ej. 1A1000101000100).', 422);
            $datos = ['nombre' => _texto_obligatorio($in, 'nombre', 'El nombre', 500), 'activo' => $editar ? $activo : 1];
            break;

        case 'campo_tec': // campos que se envían a SIESA como descripciones técnicas (f104_id)
            $tabla = 'cat_campo_tecnico'; $llave = ['f104_id' => normalizar_texto($in['f104_id'] ?? '')];
            if ($llave['f104_id'] === '' || mb_strlen($llave['f104_id']) > 80) throw new ErrorApi('El nombre del campo (f104_id) es obligatorio (máx. 80).', 422);
            $orden = trim((string) ($in['orden'] ?? ''));
            if ($orden !== '' && !preg_match('/^\d{1,4}$/', $orden)) throw new ErrorApi('El orden debe ser un número entero.', 422);
            if ($orden === '') $orden = (string) $pdo->query('SELECT COALESCE(MAX(orden), 0) + 1 FROM cat_campo_tecnico')->fetchColumn();
            $datos = ['orden' => (int) $orden, 'activo' => $editar ? $activo : 1];
            break;

        default:
            throw new ErrorApi('Catálogo no válido.', 422);
    }

    $where = implode(' AND ', array_map(fn($k) => "$k = ?", array_keys($llave)));
    $antes = $buscar("SELECT * FROM $tabla WHERE $where", array_values($llave));
    if ($editar && !$antes) throw new ErrorApi('Registro no encontrado.', 404);
    if (!$editar && $antes) throw new ErrorApi('Ya existe un registro con ese código.', 409);

    if ($editar) {
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($datos)));
        $pdo->prepare("UPDATE $tabla SET $set WHERE $where")->execute([...array_values($datos), ...array_values($llave)]);
    } else {
        insertar($pdo, $tabla, $llave + $datos);
    }
    $despues = $buscar("SELECT * FROM $tabla WHERE $where", array_values($llave));
    auditar($usuarioId, $editar ? 'CATALOGO_EDITAR' : 'CATALOGO_CREAR', $tabla, implode('|', $llave), $antes, $despues);
    return $despues;
}

// ------------------------------------------------------------------ listas desplegables (catálogos de lista)
/** Listas con su número de valores activos / totales (una consulta). */
function listas_estado(PDO $pdo): array {
    return $pdo->query(
        "SELECT l.codigo, l.nombre, l.descripcion, l.es_sistema, l.activo, l.orden,
                SUM(CASE WHEN v.activo = 1 THEN 1 ELSE 0 END) AS activos, COUNT(v.id) AS total
           FROM cat_lista l LEFT JOIN cat_lista_valor v ON v.lista = l.codigo
          GROUP BY l.codigo, l.nombre, l.descripcion, l.es_sistema, l.activo, l.orden
          ORDER BY l.orden, l.nombre")->fetchAll();
}

/** Crea un catálogo de lista nuevo. El código se deriva del nombre (TIPO DE X -> TIPO_DE_X). */
function lista_crear(PDO $pdo, array $in, int $usuarioId): array {
    // El nombre se muestra tal como se escribe (solo se quitan espacios sobrantes); el código y la
    // comparación de duplicados usan la versión en mayúsculas.
    $nombre = trim(preg_replace('/\s+/u', ' ', (string) ($in['nombre'] ?? '')));
    if ($nombre === '') throw new ErrorApi('El nombre del catálogo es obligatorio.', 422);
    if (mb_strlen($nombre) > 100) throw new ErrorApi('El nombre del catálogo supera 100 caracteres.', 422);
    if (mb_strlen((string) ($in['descripcion'] ?? '')) > 300) throw new ErrorApi('La descripción supera 300 caracteres.', 422);
    $mayus = normalizar_texto($nombre);
    $codigo = trim(preg_replace('/[^A-Z0-9]+/', '_', strtr($mayus, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N'])), '_');
    if ($codigo === '' || mb_strlen($codigo) > 40) throw new ErrorApi('Nombre no válido para generar el código del catálogo (máx. 40 caracteres).', 422);
    $st = $pdo->prepare('SELECT codigo, nombre FROM cat_lista WHERE codigo = ? OR UPPER(nombre) = ?');
    $st->execute([$codigo, $mayus]);
    if ($o = $st->fetch()) throw new ErrorApi("Ya existe el catálogo '{$o['nombre']}'.", 409);
    $orden = (int) $pdo->query('SELECT COALESCE(MAX(orden), 0) + 1 FROM cat_lista')->fetchColumn();
    $fila = ['codigo' => $codigo, 'nombre' => $nombre, 'descripcion' => trim(preg_replace('/\s+/u', ' ', (string) ($in['descripcion'] ?? ''))) ?: null,
             'es_sistema' => 0, 'activo' => 1, 'orden' => $orden, 'creado_en' => ahora(), 'creado_por' => $usuarioId];
    insertar($pdo, 'cat_lista', $fila);
    auditar($usuarioId, 'LISTA_CREAR', 'cat_lista', $codigo, null, $fila);
    return $fila;
}

/**
 * Crea un valor en una lista, o cambia orden/estado de uno existente.
 * El texto del valor NO se renombra: los medicamentos creados guardan ese texto (p.ej. el envase
 * forma la presentación agrupada). Para corregirlo: desactivar y crear el correcto.
 */
function lista_valor_guardar(PDO $pdo, array $in, int $usuarioId): array {
    $id = (int) ($in['id'] ?? 0);
    $orden = trim((string) ($in['orden'] ?? ''));
    if ($orden !== '' && !preg_match('/^\d{1,4}$/', $orden)) throw new ErrorApi('El orden debe ser un número entero.', 422);
    if ($id) {
        $antes = _fila($pdo, 'cat_lista_valor', $id);
        $activo = !empty($in['activo']) ? 1 : 0;
        $pdo->prepare('UPDATE cat_lista_valor SET orden = ?, activo = ? WHERE id = ?')->execute([$orden === '' ? $antes['orden'] : (int) $orden, $activo, $id]);
    } else {
        $antes = null;
        $lista = (string) ($in['lista'] ?? '');
        $st = $pdo->prepare('SELECT COUNT(*) FROM cat_lista WHERE codigo = ?');
        $st->execute([$lista]);
        if (!(int) $st->fetchColumn()) throw new ErrorApi('Catálogo de lista no encontrado.', 404);
        $valor = _texto_obligatorio($in, 'valor', 'El valor', 150);
        $st = $pdo->prepare('SELECT valor FROM cat_lista_valor WHERE lista = ? AND UPPER(valor) = ?');
        $st->execute([$lista, $valor]);
        if ($st->fetchColumn() !== false) throw new ErrorApi("El valor '$valor' ya existe en este catálogo.", 409);
        if ($orden === '') {
            $st = $pdo->prepare('SELECT COALESCE(MAX(orden), 0) + 1 FROM cat_lista_valor WHERE lista = ?');
            $st->execute([$lista]);
            $orden = (string) $st->fetchColumn();
        }
        $id = insertar($pdo, 'cat_lista_valor', ['lista' => $lista, 'valor' => $valor, 'orden' => (int) $orden, 'activo' => 1]);
    }
    $despues = _fila($pdo, 'cat_lista_valor', $id);
    auditar($usuarioId, $antes ? 'LISTA_VALOR_EDITAR' : 'LISTA_VALOR_CREAR', 'cat_lista_valor', $id, $antes, $despues);
    return $despues;
}

/** Valores existentes para sugerir al digitar (evita variantes como 'INYACTABLE'). */
function molecula_sugerencias(PDO $pdo): array {
    $r = [];
    foreach (['forma_farmaceutica', 'grupo_farmacologico', 'via_administracion'] as $c) {
        $r[$c] = $pdo->query("SELECT $c FROM cat_molecula WHERE $c <> '' GROUP BY $c ORDER BY COUNT(*) DESC LIMIT 300")->fetchAll(PDO::FETCH_COLUMN);
    }
    $r['envases'] = $pdo->query("SELECT valor FROM cat_lista_valor WHERE lista = 'ENVASE' AND activo = 1 ORDER BY orden")->fetchAll(PDO::FETCH_COLUMN);
    // Tipos de medicamento que aún no tienen tipo de inventario asignado
    $r['tipos_sin_inventario'] = $pdo->query("SELECT v.valor FROM cat_lista_valor v WHERE v.lista = 'TIPO_MEDICAMENTO' AND v.activo = 1
        AND NOT EXISTS (SELECT 1 FROM cat_tipo_inventario t WHERE UPPER(t.tipo_medicamento) = UPPER(v.valor)) ORDER BY v.orden")->fetchAll(PDO::FETCH_COLUMN);
    $r['listas'] = MOLECULA_LISTAS;
    return $r;
}
