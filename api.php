<?php
/**
 * API del aplicativo: api.php?accion=<nombre>
 *  - GET  para consultas, POST (JSON) para cambios. Todo POST exige el encabezado X-CSRF-Token.
 *  - Cada acción declara el permiso que requiere; se valida aquí, no solo en el navegador.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/datos.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/bases.php';
require_once __DIR__ . '/lib/catalogos_admin.php';
require_once __DIR__ . '/lib/catalogos_excel.php';
require_once __DIR__ . '/lib/invima.php';
require_once __DIR__ . '/lib/nota_tecnica.php';
require_once __DIR__ . '/lib/nt_publicacion.php';
require_once __DIR__ . '/lib/solicitudes.php';
require_once __DIR__ . '/lib/creacion.php';
require_once __DIR__ . '/lib/correcciones.php';

// Cargues de bases (hasta 200 MB) y el cálculo de la Maestra Tarifaria necesitan más memoria y tiempo que los valores por defecto.
// upload_max_filesize y post_max_size no se cambian desde aquí: los fija TIC en php.ini (ver README).
@ini_set('memory_limit', '1024M');
@set_time_limit(300);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function responder($data, int $codigo = 200): never {
    http_response_code($codigo);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function entrada(): array {
    static $cuerpo = null;
    if ($cuerpo === null) {
        $cuerpo = json_decode(file_get_contents('php://input') ?: '[]', true);
        if (!is_array($cuerpo)) throw new ErrorApi('Solicitud inválida.', 400);
    }
    return $cuerpo;
}

/** Ejecuta un cambio en una transacción: o queda completo (con su auditoría) o no queda nada. */
function transaccion(callable $fn) {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $r = $fn();
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Filtros por columna (?filtros={"campo":"texto"}) -> condiciones SQL parametrizadas.
 * $permitidos: nombre del filtro => expresión SQL. Solo se aceptan esas columnas (nunca texto libre).
 * Columnas de estado (activo, habilitado...) filtran exacto por 1/0; el resto por "contiene".
 */
/**
 * Filtros por columna (en el servidor, solo sobre columnas permitidas).
 * - Lista de valores (autofiltro como Excel): ["259", "2259"] -> la columna es uno de esos valores;
 *   "" representa las celdas vacías.
 * - Texto: coincidencia parcial (compatibilidad).
 * $excluir: columna cuyo filtro no se aplica (al pedir sus propios valores, igual que Excel).
 */
/**
 * Actividad reciente para el tablero: las últimas acciones legibles (sin descargas ni exportaciones).
 * Sin permiso de auditoría solo se ven las acciones propias.
 */
function actividad_reciente(PDO $pdo, ?int $usuarioId, int $n): array {
    $txt = [
        'LOGIN' => ['Último inicio de sesión', 'Acceso al aplicativo', 'usuario'], 'BASE_CARGAR' => ['Se cargó una base de referencia', null, 'archivo'],
        'BASE_LEER' => ['Se leyó una base de referencia', null, 'archivo'], 'INVIMA_ACTUALIZAR' => ['Se actualizó la base INVIMA', 'Proceso finalizado', 'ok'],
        'MUTUAL_ACTUALIZAR' => ['Se actualizó la Maestra Mutual Ser', 'Proceso finalizado', 'ok'], 'PRODUCTOS_CREAR' => ['Se crearon productos', 'Se generaron los 6 archivos de cargue', 'ok'],
        'SOLICITUD_IMPORTAR' => ['Se cargaron productos desde Excel', null, 'archivo'], 'SOLICITUD_ITEM_CREAR' => ['Se registró un producto por crear', null, 'archivo'],
        'NT_CREAR' => ['Se creó una línea en la Maestra Tarifaria', null, 'ok'], 'NT_EDITAR' => ['Se editó la Maestra Tarifaria', null, 'archivo'],
        'NT_IMPORTAR' => ['Se importó la Maestra Tarifaria desde Excel', null, 'archivo'], 'NT_QUITAR' => ['Se quitó una línea de la Maestra Tarifaria', null, 'alerta'],
        'MOLECULA_CREAR' => ['Se creó un COD GEN', null, 'ok'], 'LABORATORIO_CREAR' => ['Se creó un laboratorio', null, 'ok'], 'PRESENTACION_CREAR' => ['Se creó una presentación', null, 'ok'],
        'LOGIN_FALLIDO' => ['Intento de ingreso fallido', null, 'alerta'], 'PARAMETRO_EDITAR' => ['Se cambió un parámetro', null, 'archivo'],
        'APROBACION_APROBAR' => ['Se aprobó una solicitud', null, 'ok'], 'APROBACION_RECHAZAR' => ['Se rechazó una solicitud', null, 'alerta'],
    ];
    $marcas = implode(',', array_map([$pdo, 'quote'], array_keys($txt)));
    // se leen más registros de los que se muestran: los inicios de sesión repetidos se agrupan
    $st = $pdo->prepare("SELECT l.creado_en, l.accion, l.entidad_id, l.despues, l.usuario_id, u.nombre AS usuario FROM aud_log l LEFT JOIN seg_usuario u ON u.id = l.usuario_id
                          WHERE l.accion IN ($marcas)" . ($usuarioId ? ' AND l.usuario_id = ' . $usuarioId : '') . " ORDER BY l.id DESC LIMIT " . ($n * 20));
    $st->execute();
    $out = []; $ingreso = [];
    foreach ($st as $r) {
        if (count($out) >= $n) break;
        // inicio de sesión: una sola línea por usuario, con la fecha del más reciente
        if ($r['accion'] === 'LOGIN') { if (isset($ingreso[$r['usuario_id']])) continue; $ingreso[$r['usuario_id']] = true; }
        [$titulo, $detalle, $tipo] = $txt[$r['accion']];
        $d = json_decode((string) $r['despues'], true) ?: [];
        if (in_array($r['accion'], ['BASE_CARGAR', 'BASE_LEER'], true)) $detalle = trim(($d['archivo'] ?? $d['base'] ?? '') . (isset($d['filas_leidas']) ? " · {$d['filas_leidas']} registros" : (isset($d['filas']) ? " · {$d['filas']} registros" : '')));
        if ($r['accion'] === 'PRODUCTOS_CREAR' && isset($d['productos'])) $detalle = count($d['productos']) . ' producto(s) · 6 archivos de cargue';
        if ($r['accion'] === 'SOLICITUD_IMPORTAR' && isset($d['cargados'])) $detalle = "{$d['cargados']} producto(s) desde {$d['archivo']}";
        $out[] = ['fecha' => $r['creado_en'], 'usuario' => $r['usuario'], 'titulo' => $titulo, 'detalle' => $detalle, 'tipo' => $tipo];
    }
    return $out;
}

/** Envía un archivo como descarga y termina (si $temporal, lo borra después). */
/**
 * Perfiles de consulta: ven la Maestra Tarifaria solo con lo que está en el contrato (decisión del usuario, 2026-10-19).
 * Sin NT.VER_FUERA_CONTRATO se quitan las líneas con ¿ESTA EN EL CONTRATO? = NO (también de sus filtros y descargas).
 */
function nt_ver_todo(array $u): bool { return in_array('NT.VER_FUERA_CONTRATO', permisos_de($u['id']), true); }
function nt_filtros_perfil(array $u, array $filtros): array {
    if (nt_ver_todo($u)) return $filtros;
    $v = array_values(array_diff(array_map('strval', (array) ($filtros['en_contrato'] ?? ['SI', 'NO APLICA'])), ['NO']));
    return ['en_contrato' => $v ?: ['(ninguno)']] + $filtros;
}
function requerir_ver_todo(array $u): void {
    if (!nt_ver_todo($u)) throw new ErrorApi('Su perfil ve solo las líneas que están en el contrato: use «Exportar».', 403);
}

/** ¿El usuario ve precios y tarifas? (el perfil «Consulta sin precios» no) */
function ver_precios(array $u): bool { return in_array('PRECIO.VER', permisos_de($u['id']), true); }
function requerir_precios(array $u): void { if (!ver_precios($u)) throw new ErrorApi('Su perfil no tiene acceso a precios ni tarifas.', 403); }
/** Base con precios: sin PRECIO.VER no se consultan sus datos ni se descarga. */
function requerir_base_visible(array $u, string $base): void { if (in_array($base, BASES_CON_PRECIOS, true)) requerir_precios($u); }

function enviar_archivo(string $ruta, string $nombre, bool $temporal = false): never {
    $tipos = ['xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'csv' => 'text/csv', 'xlsm' => 'application/vnd.ms-excel.sheet.macroEnabled.12'];
    header_remove('Content-Type');
    header('Content-Type: ' . ($tipos[strtolower(pathinfo($nombre, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $nombre) . '"');
    header('Content-Length: ' . filesize($ruta));
    readfile($ruta);
    if ($temporal) @unlink($ruta);
    exit;
}

function filtros_sql(array $permitidos, array $exactos = [], ?string $excluir = null): array {
    $filtros = json_decode((string) ($_GET['filtros'] ?? '{}'), true);
    $where = []; $params = [];
    foreach (is_array($filtros) ? $filtros : [] as $campo => $valor) {
        if ($campo === $excluir) continue;
        if (is_array($valor)) {
            if (!isset($permitidos[$campo])) continue;
            $col = $permitidos[$campo];
            $valores = array_values(array_unique(array_map(fn($v) => (string) $v, array_slice($valor, 0, 5000))));
            $conValor = array_values(array_filter($valores, fn($v) => $v !== ''));
            $partes = [];
            if ($conValor) {
                $partes[] = "CAST($col AS CHAR) IN (" . implode(', ', array_fill(0, count($conValor), '?')) . ')';
                array_push($params, ...$conValor);
            }
            if (count($conValor) < count($valores)) $partes[] = "($col IS NULL OR CAST($col AS CHAR) = '')";
            $where[] = $partes ? '(' . implode(' OR ', $partes) . ')' : '1 = 0';
            continue;
        }
        $valor = trim((string) $valor);
        if ($valor === '' || !isset($permitidos[$campo])) continue;
        if (in_array($campo, $exactos, true)) {
            $where[] = "{$permitidos[$campo]} = ?";
            $params[] = (int) $valor;
        } else {
            $where[] = "UPPER(CAST({$permitidos[$campo]} AS CHAR)) LIKE ?";
            $params[] = '%' . mb_strtoupper($valor) . '%';
        }
    }
    return [$where, $params];
}

/**
 * Valores distintos de una columna (lista del autofiltro), con los demás filtros aplicados y el texto
 * buscado (parámetro "busqueda"). Máximo 1.000 valores; "mas" indica que hay más (escribir para buscar).
 */
function valores_columna(string $from, string $col, array $cond, array $params): array {
    $b = trim((string) ($_GET['busqueda'] ?? ''));
    if ($b !== '') { $cond[] = "UPPER(CAST($col AS CHAR)) LIKE ?"; $params[] = '%' . mb_strtoupper($b) . '%'; }
    $where = $cond ? 'WHERE ' . implode(' AND ', $cond) : '';
    $st = db()->prepare("SELECT DISTINCT $col AS v FROM $from $where ORDER BY v LIMIT 1001");
    $st->execute($params);
    $v = array_values(array_unique(array_map(fn($x) => $x === null ? '' : (string) $x, $st->fetchAll(PDO::FETCH_COLUMN))));
    return ['valores' => array_slice($v, 0, 1000), 'mas' => count($v) > 1000];
}

function texto(array $in, string $k, int $max = 255, bool $obligatorio = false): ?string {
    $v = isset($in[$k]) ? trim((string) $in[$k]) : '';
    if ($v === '') {
        if ($obligatorio) throw new ErrorApi("El campo '$k' es obligatorio.", 422);
        return null;
    }
    if (mb_strlen($v) > $max) throw new ErrorApi("El campo '$k' supera $max caracteres.", 422);
    return $v;
}

/** Sistema de correcciones pedido (SIESA o MEDICAR). */
function cor_sistema(?string $s = null): string {
    $s = mb_strtoupper(trim($s ?? (string) ($_GET['sistema'] ?? 'SIESA')));
    if (!isset(COR_SISTEMAS[$s])) throw new ErrorApi('Sistema no válido.', 422);
    return $s;
}

/**
 * Después de cargar o releer el Maestro SIESA / la Maestra MEDICAR se revisan sus correcciones (si falla, el cargue sigue bien).
 * MEDICAR toma el PLU de SIESA: al cargar SIESA se revisan los dos.
 */
function cor_revisar_tras_cargue(string $base, int $usuarioId): void {
    $sistemas = ['MAESTRO_SIESA' => ['SIESA', 'MEDICAR'], 'MAESTRA_MEDICAR' => ['MEDICAR']][$base] ?? [];
    foreach ($sistemas as $sistema) {
        try { transaccion(fn() => cor_revisar(db(), $sistema, $usuarioId)); } catch (Throwable $e) { error_log('[cor_revisar] ' . $e); }
    }
}

/** Permisos que solo puede tener el perfil Administrador (decisiones del usuario, 2026-09-25). */
const PERMISOS_SOLO_ADMIN = ['BASE.ACTUALIZAR', 'CATALOGO.EDITAR', 'USUARIO.GESTIONAR', 'PERFIL.ASIGNAR', 'PARAMETRO.ADMINISTRAR', 'PRECIO.ASIGNAR', 'PRECIO.APROBAR_EXCEPCION', 'NT.GESTIONAR'];

// ------------------------------------------------------------------ catálogos consultables
const CATALOGOS = [
    'molecula'     => ['cat_molecula', 'id, cod_gen_audifarma, descripcion_general, codigo_medicar, concentracion_agrupada, principio_activo, forma_farmaceutica, grupo_farmacologico, via_administracion, pos_no_pos, cadena_frio, clasificacion_entrega, cantidad_tope, activo', ['cod_gen_audifarma', 'descripcion_general', 'codigo_medicar', 'principio_activo'], 'cod_gen_audifarma'],
    'laboratorio'  => ['cat_laboratorio', 'id, codigo, nombre, activo', ['codigo', 'nombre'], 'nombre'],
    'presentacion' => ['cat_presentacion', 'id, codigo, presentacion_agrupada, unidad_orden, activo', ['codigo', 'presentacion_agrupada', 'unidad_orden'], 'presentacion_agrupada'],
    'lista'        => ['cat_lista_valor', 'id, lista, valor, orden, activo', ['valor'], 'orden, valor'],
    'subplan'      => ['cat_subplan', 'id_subplan, nombre, clasificacion, fuente, activo, actualizado_en', ['nombre'], 'id_subplan'],
    'grupo_imp'    => ['cat_grupo_impositivo', 'codigo, etiqueta_iva, descripcion, iva_medicar, activo', ['codigo', 'descripcion'], 'codigo'],
    'tipo_inv'     => ['cat_tipo_inventario', 'codigo, tipo_medicamento, descripcion, activo', ['codigo', 'descripcion'], 'codigo'],
    'rips'         => ['cat_rips', 'id, tabla, codigo, nombre, habilitado', ['tabla', 'codigo', 'nombre'], 'tabla, nombre'],
    'ium'          => ['cat_ium', 'codigo, nombre, activo', ['codigo', 'nombre'], 'codigo'],
    'campo_tec'    => ['cat_campo_tecnico', 'id, orden, f104_id, activo', ['f104_id'], 'orden'],
    'regla'        => ['proc_regla', 'codigo, severidad, descripcion, base_comparada, accion_sugerida, activa, personalizada, ambito, campo, condicion, valor', ['codigo', 'descripcion', 'severidad'], 'orden'],
];

$acciones = [
    // ---------------------------------------------------------------- sesión
    'sesion' => ['GET', function () {
        $u = usuario_actual();
        if ($u) {
            try { $u = requerir_login(true); } catch (ErrorApi) { $u = null; }
        }
        return [
            'app' => APP_NOMBRE, 'version' => APP_VERSION, 'modo_prueba' => modo_prueba_local(),
            'csrf' => $_SESSION['csrf'], 'usuario' => $u,
            'permisos' => $u ? permisos_de($u['id']) : [], 'perfiles' => $u ? perfiles_de($u['id']) : [],
        ];
    }],
    'login' => ['POST', function () {
        $in = entrada();
        $u = login((string) ($in['usuario'] ?? ''), (string) ($in['clave'] ?? ''));
        return ['usuario' => $u, 'permisos' => permisos_de($u['id']), 'perfiles' => perfiles_de($u['id']), 'csrf' => $_SESSION['csrf']];
    }],
    'logout' => ['POST', function () { logout(); return ['ok' => true, 'csrf' => $_SESSION['csrf'] ?? null]; }],
    'cambiar_clave' => ['POST', function () {
        $u = requerir_login(true);
        $in = entrada();
        $st = db()->prepare('SELECT password_hash FROM seg_usuario WHERE id = ?');
        $st->execute([$u['id']]);
        if (!password_verify((string) ($in['actual'] ?? ''), (string) $st->fetchColumn())) throw new ErrorApi('La contraseña actual no es correcta.', 422);
        $nueva = (string) ($in['nueva'] ?? '');
        validar_clave_nueva($nueva);
        if (hash_equals((string) ($in['actual'] ?? ''), $nueva)) throw new ErrorApi('La nueva contraseña debe ser distinta a la actual.', 422);
        db()->prepare('UPDATE seg_usuario SET password_hash = ?, debe_cambiar_clave = 0 WHERE id = ?')->execute([password_hash($nueva, PASSWORD_DEFAULT), $u['id']]);
        $_SESSION['usuario']['debe_cambiar_clave'] = false;
        auditar($u['id'], 'CAMBIO_CLAVE', 'seg_usuario', $u['id']);
        return ['ok' => true];
    }],

    // ---------------------------------------------------------------- inicio
    'resumen' => ['GET', function () {
        requerir_login();
        $pdo = db();
        $conteo = [];
        foreach (CATALOGOS as $k => [$tabla]) $conteo[$k] = (int) $pdo->query("SELECT COUNT(*) FROM $tabla")->fetchColumn();
        // Tablero «Centro de control» (fase 10): indicadores, comparativos y actividad reciente
        $mes = date('Y-m-01 00:00:00');
        $semana = date('Y-m-d 00:00:00', strtotime('monday this week'));
        $nuevos = fn(string $accion) => (int) db()->query("SELECT COUNT(*) FROM aud_log WHERE accion = " . db()->quote($accion) . " AND creado_en >= " . db()->quote($mes))->fetchColumn();
        $porCrear = $pdo->query("SELECT i.resultado, COUNT(*) FROM proc_lote_item i JOIN proc_lote l ON l.id = i.lote_id
                                  WHERE l.estado IN ('BORRADOR', 'VALIDADA') GROUP BY i.resultado")->fetchAll(PDO::FETCH_KEY_PAIR);
        $u = usuario_actual();
        $verTodo = in_array('AUDITORIA.CONSULTAR', permisos_de($u['id']), true);
        return [
            'catalogos' => $conteo, 'bases' => bases_estado($pdo),
            'productos' => (int) $pdo->query('SELECT COUNT(*) FROM prod_producto')->fetchColumn(),
            'productos_semana' => (int) $pdo->query('SELECT COUNT(*) FROM prod_producto WHERE creado_en >= ' . $pdo->quote($semana))->fetchColumn(),
            'por_crear' => ['total' => array_sum($porCrear), 'aptos' => ($porCrear['VALIDADO'] ?? 0) + ($porCrear['CON ALERTA'] ?? 0), 'error' => $porCrear['ERROR'] ?? 0],
            'catalogos_mes' => ['molecula' => $nuevos('MOLECULA_CREAR'), 'laboratorio' => $nuevos('LABORATORIO_CREAR'), 'presentacion' => $nuevos('PRESENTACION_CREAR')],
            'actividad' => actividad_reciente($pdo, $verTodo ? null : (int) $u['id'], 4),
            'ver_auditoria' => $verTodo,
        ];
    }],

    // ---------------------------------------------------------------- bases externas
    'bases' => ['GET', function () {
        requerir_login();
        return ['bases' => bases_estado(db()), 'puede_cargar' => in_array('BASE.ACTUALIZAR', permisos_de(usuario_actual()['id']), true),
                'tamano_max_mb' => (int) parametro('BASE_TAMANO_MAX_MB', '200')];
    }],
    'base_historial' => ['GET', function () {
        $u = requerir_login();
        $base = (string) ($_GET['base'] ?? '');
        $h = base_historial(db(), $base);
        if (in_array($base, BASES_CON_PRECIOS, true) && !ver_precios($u)) foreach ($h as &$x) { $x['descargable'] = false; $x['sin_precios'] = true; }
        return ['historial' => $h];
    }],
    'base_cargar' => ['POST', function () {
        $u = requerir_permiso('BASE.ACTUALIZAR');
        if (empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            throw new ErrorApi('El archivo supera el tamaño que acepta el servidor (post_max_size).', 413);
        }
        $r = base_cargar_manual(db(), (string) ($_POST['base'] ?? ''), $_FILES['archivo'] ?? [], $u['id'], $_POST['nombre_version'] ?? null, $_POST['vigente_desde'] ?? null, $_POST['vigente_hasta'] ?? null);
        cor_revisar_tras_cargue((string) ($_POST['base'] ?? ''), $u['id']);
        return $r;
    }],
    'base_revisar_contrato' => ['POST', function () {
        requerir_permiso('BASE.ACTUALIZAR');
        $a = $_FILES['archivo'] ?? [];
        if (($a['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($a['tmp_name'] ?? '')) throw new ErrorApi('No se recibió el archivo.', 422);
        return contrato_revisar(db(), $a['tmp_name'], basename((string) $a['name']));
    }],
    'base_version_editar' => ['POST', function () {
        $u = requerir_permiso('BASE.ACTUALIZAR');
        $in = entrada();
        return transaccion(fn() => base_version_editar(db(), (int) ($in['id'] ?? 0), $in, $u['id']));
    }],
    'base_plantilla' => ['GET', function () {
        $u = requerir_permiso('BASE.ACTUALIZAR');
        $tmp = tempnam(sys_get_temp_dir(), 'pla');
        $nombre = base_plantilla(db(), (string) ($_GET['base'] ?? ''), $tmp);
        auditar($u['id'], 'BASE_PLANTILLA', 'ref_base', (string) ($_GET['base'] ?? ''), null, null);
        enviar_archivo($tmp, $nombre, true);
    }],
    'base_version_descargar' => ['GET', function () {
        $u = requerir_login(); // el historial de bases lo consultan todos los perfiles
        $id = (int) ($_GET['id'] ?? 0);
        $c = base_version_ruta(db(), $id);
        requerir_base_visible($u, $c['tipo_base']);
        auditar($u['id'], 'BASE_VERSION_DESCARGAR', 'ref_carga', $id, null, null);
        enviar_archivo($c['ruta'], "{$c['nombre']} v{$c['version']}." . pathinfo($c['ruta'], PATHINFO_EXTENSION));
    }],
    'base_cambios_exportar' => ['GET', function () {
        $u = requerir_login(); // el historial de bases lo consultan todos los perfiles
        $id = (int) ($_GET['id'] ?? 0);
        $st = db()->prepare('SELECT tipo_base FROM ref_carga WHERE id = ?'); $st->execute([$id]);
        requerir_base_visible($u, (string) $st->fetchColumn());
        $tmp = tempnam(sys_get_temp_dir(), 'cmb');
        $nombre = base_cambios_exportar(db(), $id, $tmp);
        auditar($u['id'], 'BASE_CAMBIOS_EXPORTAR', 'ref_carga', $id, null, null);
        enviar_archivo($tmp, $nombre, true);
    }],
    'base_procesar' => ['POST', function () {
        $u = requerir_permiso('BASE.ACTUALIZAR');
        $r = ['lectura' => base_procesar_vigente(db(), (string) (entrada()['base'] ?? ''), $u['id'])];
        cor_revisar_tras_cargue((string) (entrada()['base'] ?? ''), $u['id']);
        return $r;
    }],
    'base_datos' => ['GET', function () {
        $u = requerir_login();
        $codigo = (string) ($_GET['base'] ?? '');
        requerir_base_visible($u, $codigo);
        $vista = (string) ($_GET['vista'] ?? '');
        $def = base_consulta_def($codigo, $vista);
        if (!$def) throw new ErrorApi('Base sin datos leídos.', 404);
        $cols = array_keys($def['columnas']);
        $campo = $_GET['valores_de'] ?? null;
        if ($campo !== null) {
            if (!in_array($campo, $cols, true)) throw new ErrorApi('Columna no válida.', 422);
            [$cond, $params] = filtros_sql(array_combine($cols, $cols), [], $campo);
            $st = db()->prepare('SELECT id FROM ref_carga WHERE tipo_base = ? AND vigente = 1 ORDER BY id DESC LIMIT 1');
            $st->execute([$codigo]);
            return valores_columna($def['tabla'], $campo, ['carga_id = ?', ...$cond], [(int) $st->fetchColumn(), ...$params]);
        }
        [$cond, $params] = filtros_sql(array_combine($cols, $cols));
        return base_datos(db(), $codigo, max(1, (int) ($_GET['pagina'] ?? 1)), min(100, max(10, (int) ($_GET['tam'] ?? 25))), $cond, $params, $vista);
    }],
    'base_actualizar' => ['POST', function () {
        $u = requerir_permiso('BASE.ACTUALIZAR');
        $base = (string) (entrada()['base'] ?? '');
        if (!isset(SCRIPTS_DESCARGA[$base])) throw new ErrorApi('Esta base no se descarga automáticamente.', 422);
        return ['proceso' => proceso_lanzar(db(), $base, SCRIPTS_DESCARGA[$base], $u['id'], !empty(entrada()['forzar']))];
    }],
    'proceso_estado' => ['GET', function () {
        requerir_login();
        $st = db()->prepare('SELECT id, tipo, estado, avance, mensaje, iniciado_en, actualizado_en, terminado_en FROM sis_proceso WHERE ' .
            (isset($_GET['id']) ? 'id = ?' : "tipo = ? ORDER BY id DESC LIMIT 1"));
        $st->execute([isset($_GET['id']) ? (int) $_GET['id'] : (string) ($_GET['tipo'] ?? 'INVIMA')]);
        return ['proceso' => $st->fetch() ?: null];
    }],
    'base_config_guardar' => ['POST', function () {
        $u = requerir_permiso('BASE.ACTUALIZAR');
        $in = entrada();
        return ['base' => base_config_guardar(db(), (string) ($in['base'] ?? ''), (int) ($in['dias_alerta'] ?? 0), $u['id'])];
    }],
    // ---------------- MAESTRA TARIFARIA (fase 4)
    'nt_listar' => ['GET', function () {
        $u = requerir_permiso('NT.CONSULTAR');
        $filtros = json_decode((string) ($_GET['filtros'] ?? '{}'), true);
        $tam = min(200, max(10, (int) ($_GET['tam'] ?? 50)));
        $permisos = permisos_de($u['id']);
        $conPrecios = in_array('PRECIO.VER', $permisos, true);
        $filtros = nt_filtros_perfil($u, is_array($filtros) ? $filtros : []);
        if (!$conPrecios) {
            $filtros = nt_filtros_sin_precios($filtros);
            if (in_array((string) ($_GET['valores_de'] ?? ''), NT_CAMPOS_PRECIO, true)) throw new ErrorApi('Su perfil no tiene acceso a precios ni tarifas.', 403);
        }
        if (isset($_GET['valores_de'])) return nt_valores(db(), $filtros, (string) $_GET['valores_de'], (string) ($_GET['busqueda'] ?? ''));
        $r = nt_listar(db(), $filtros, max(1, (int) ($_GET['pagina'] ?? 1)), $tam);
        if (!$conPrecios) $r['filas'] = array_map('nt_sin_precios', $r['filas']);
        return $r + ['con_precios' => $conPrecios, 'puede_gestionar' => in_array('NT.GESTIONAR', $permisos, true), 'puede_descargar' => in_array('NT.DESCARGAR', $permisos, true),
               'porcentaje_precio' => (float) parametro('NT_PORCENTAJE_PRECIO', '20'), 'anio_precio' => parametro('NT_ANIO_PRECIO', '2026')];
    }],
    // Publicación para sistemas externos (llave de API en X-API-Key, sin sesión): nt_version indica si algo cambió; nt_snapshot entrega todo lo calculado.
    'nt_version' => ['GET', function () {
        requerir_llave_publicacion();
        return nt_version_datos(db());
    }],
    'nt_snapshot' => ['GET', function () {
        requerir_llave_publicacion();
        $v = nt_version_datos(db()); // antes de calcular: si algo cambia durante el cálculo, la siguiente consulta de nt_version lo detecta
        $pub = nt_filas_publicables(db());
        $conPrecios = app_config()['publicacion_con_precios'] === '1';
        $filas = $conPrecios ? $pub['filas'] : array_map('nt_sin_precios', $pub['filas']);
        $json = json_encode(['version' => $v['version'], 'generado_en' => ahora(), 'total' => count($filas), 'con_precios' => $conPrecios,
                             'bases_faltantes' => $pub['bases_faltantes'], 'filas' => $filas], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        auditar(null, 'NT_SNAPSHOT', 'nt_item', null, null, ['version' => $v['version'], 'filas' => count($filas), 'con_precios' => $conPrecios, 'via' => 'llave_api']);
        header('Vary: Accept-Encoding');
        if (str_contains((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip')) { $json = gzencode($json, 6); header('Content-Encoding: gzip'); }
        header('Content-Length: ' . strlen($json));
        echo $json;
        exit;
    }],
    'nt_precio_uno' => ['GET', function () {
        requerir_precios(requerir_permiso('NT.CONSULTAR'));
        return ['filas' => nt_precio_uno(db())];
    }],
    'nt_quitar_medicar' => ['POST', function () {
        $u = requerir_permiso('NT.GESTIONAR');
        return transaccion(fn() => nt_quitar_medicar(db(), $u['id']));
    }],
    'nt_cambios' => ['GET', function () {
        requerir_permiso('NT.CONSULTAR');
        $f = fn($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET[$k] ?? '')) ? $_GET[$k] : null;
        $u = usuario_actual();
        return ['cambios' => iterator_to_array(nt_cambios_visibles(nt_cambios(db(), $f('desde'), $f('hasta'), 3000), ver_precios($u)), false)];
    }],
    'nt_cambios_exportar' => ['GET', function () {
        $u = requerir_permiso('NT.DESCARGAR');
        $f = fn($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET[$k] ?? '')) ? $_GET[$k] : null;
        $tmp = tempnam(sys_get_temp_dir(), 'ntc');
        XlsxWriter::guardar($tmp, [['nombre' => 'CAMBIOS MAESTRA TARIFARIA', 'encabezados' => ['FECHA', 'USUARIO', 'CAMBIO', 'CUM FACTURAR', 'CONTRATO · SUBPLAN', 'CAMPO', 'ANTES', 'DESPUÉS'],
            'filas' => nt_cambios_visibles(nt_cambios(db(), $f('desde'), $f('hasta')), ver_precios($u))]]);
        auditar($u['id'], 'NT_CAMBIOS_EXPORTAR', 'nt_item', null, null, ['desde' => $f('desde'), 'hasta' => $f('hasta')]);
        enviar_archivo($tmp, 'MAESTRA TARIFARIA - CAMBIOS - ' . date('Ymd') . '.xlsx', true);
    }],
    // Maestra Tarifaria al cierre de una fecha: revisar (para avisar si no se puede) y descargar
    'nt_al_corte_revisar' => ['GET', function () {
        $u = requerir_permiso('NT.DESCARGAR');
        if (!ver_precios($u)) return ['ok' => false, 'mensaje' => 'Su perfil no tiene acceso a precios: las versiones guardadas incluyen precios. Use «Exportar» (sin precios).'];
        if (!nt_ver_todo($u)) return ['ok' => false, 'mensaje' => 'Su perfil ve solo las líneas que están en el contrato y las versiones guardadas las traen todas: use «Exportar».'];
        try { $r = nt_al_corte(db(), (string) ($_GET['fecha'] ?? '')); }
        catch (ErrorApi $e) { return ['ok' => false, 'mensaje' => $e->getMessage()]; }
        if ($r['temporal']) @unlink($r['ruta']);
        return ['ok' => true, 'origen' => $r['origen']];
    }],
    'nt_al_corte' => ['GET', function () {
        $u = requerir_permiso('NT.DESCARGAR');
        requerir_precios($u);
        requerir_ver_todo($u);
        $fecha = (string) ($_GET['fecha'] ?? '');
        $r = nt_al_corte(db(), $fecha);
        auditar($u['id'], 'NT_CORTE_DESCARGAR', 'nt_item', null, null, ['fecha' => $fecha, 'origen' => $r['origen']]);
        enviar_archivo($r['ruta'], $r['nombre'], $r['temporal']);
    }],
    'nt_versiones' => ['GET', function () {
        requerir_permiso('NT.CONSULTAR');
        return ['versiones' => nt_versiones(db())];
    }],
    'nt_version_descargar' => ['GET', function () {
        $u = requerir_permiso('NT.DESCARGAR');
        requerir_precios($u); // las versiones guardadas incluyen precios
        requerir_ver_todo($u); // y todas las líneas (también las que no están en el contrato)
        $id = (int) ($_GET['id'] ?? 0);
        $ruta = nt_version_ruta(db(), $id);
        auditar($u['id'], 'NT_VERSION_DESCARGAR', 'ref_carga', $id, null, null);
        header_remove('Content-Type');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="MAESTRA TARIFARIA - ' . basename($ruta) . '"');
        header('Content-Length: ' . filesize($ruta));
        readfile($ruta);
        exit;
    }],
    'nt_validar_cum' => ['POST', function () {
        $u = requerir_permiso('NT.GESTIONAR');
        $in = entrada();
        return ['lineas' => transaccion(fn() => nt_validar_cum(db(), (string) ($in['cum_facturar'] ?? ''), !empty($in['validar']), $u['id']))];
    }],
    'nt_precio_uno_aprobar' => ['POST', function () {
        $u = requerir_permiso('NT.GESTIONAR');
        $in = entrada();
        return ['lineas' => transaccion(fn() => nt_precio_uno_aprobar(db(), (array) ($in['ids'] ?? []), !empty($in['aprobar']), $u['id']))];
    }],
    // ---------------------------------------------------------------- solicitudes de creación (fase 5)
    'sol_actual' => ['GET', function () {
        $u = requerir_permiso('PRODUCTO.CREAR');
        $id = transaccion(fn() => sol_abierta(db(), $u['id']));
        return sol_detalle(db(), $id) + ['puede_aprobar' => in_array('CATALOGO.EDITAR', permisos_de($u['id']), true),
            'aprobaciones_pendientes' => count(sol_aprobaciones(db()))];
    }],
    // carga masiva desde Excel (6.9): plantilla, vista previa y aplicar
    'sol_plantilla' => ['GET', function () {
        requerir_permiso('PRODUCTO.CREAR');
        $tmp = tempnam(sys_get_temp_dir(), 'plt');
        $nombre = sol_plantilla(db(), $tmp);
        header_remove('Content-Type');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp); @unlink($tmp);
        exit;
    }],
    'sol_importar' => ['POST', function () {
        $u = requerir_permiso('PRODUCTO.CREAR');
        $a = $_FILES['archivo'] ?? [];
        if (empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) throw new ErrorApi('El archivo supera el tamaño que acepta el servidor.', 413);
        if (($a['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($a['tmp_name'] ?? '')) throw new ErrorApi('No se recibió el archivo.', 422);
        if ($a['size'] > (int) parametro('BASE_TAMANO_MAX_MB', '200') * 1024 * 1024) throw new ErrorApi('El archivo supera el tamaño permitido.', 413);
        return sol_importar(db(), $a['tmp_name'], basename((string) $a['name']), $u['id'], ($_POST['modo'] ?? '') === 'aplicar');
    }],
    'sol_listas' => ['GET', function () {
        requerir_permiso('PRODUCTO.CREAR');
        return sol_listas(db());
    }],
    'sol_autocompletar' => ['GET', function () {
        requerir_permiso('PRODUCTO.CREAR');
        return sol_autocompletar(db(), (string) ($_GET['cod_auf'] ?? ''), (string) ($_GET['cum'] ?? ''));
    }],
    'sol_item_guardar' => ['POST', function () {
        $u = requerir_permiso('PRODUCTO.CREAR');
        $in = entrada();
        // al guardar se valida de una vez: el producto queda en el semáforo sin pasos adicionales
        return transaccion(function () use ($in, $u) {
            $id = sol_item_guardar(db(), (int) ($in['lote_id'] ?? 0), $in, $u['id']);
            return ['id' => $id, 'resumen' => sol_validar(db(), (int) ($in['lote_id'] ?? 0), $u['id'])];
        });
    }],
    'sol_item_eliminar' => ['POST', function () {
        $u = requerir_permiso('PRODUCTO.CREAR');
        $in = entrada();
        transaccion(fn() => sol_item_eliminar(db(), (int) ($in['lote_id'] ?? 0), (int) ($in['id'] ?? 0), $u['id']));
        return ['ok' => true];
    }],
    'sol_validar' => ['POST', function () {
        $u = requerir_permiso('PRODUCTO.CREAR');
        return ['resumen' => transaccion(fn() => sol_validar(db(), (int) (entrada()['id'] ?? 0), $u['id']))];
    }],
    'sol_exportar' => ['GET', function () {
        $u = requerir_permiso('PRODUCTO.CREAR');
        $tmp = tempnam(sys_get_temp_dir(), 'sol');
        $nombre = sol_exportar(db(), (int) ($_GET['id'] ?? 0), $tmp, !empty($_GET['errores']));
        auditar($u['id'], 'SOLICITUD_EXPORTAR', 'proc_lote', (int) ($_GET['id'] ?? 0), null, ['archivo' => $nombre]);
        header_remove('Content-Type');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        unlink($tmp);
        exit;
    }],
    // ---------------------------------------------------------------- creación y archivos de cargue (fase 6)
    'cre_crear' => ['POST', function () {
        $u = requerir_permiso('PRODUCTO.CREAR');
        $e = entrada();
        return transaccion(fn() => cre_crear(db(), [], $u['id'], $e['consecutivo'] ?? null)); // todos los que cumplen
    }],
    'cre_consecutivo' => ['GET', function () {
        requerir_permiso('PRODUCTO.CREAR');
        return cre_consecutivo_sugerido(db());
    }],
    'cre_historial' => ['GET', function () {
        requerir_permiso('PRODUCTO.CREAR');
        return ['creaciones' => cre_historial(db())];
    }],
    'cre_descargar' => ['GET', function () {
        $u = requerir_permiso('PRODUCTO.DESCARGAR');
        $id = (int) ($_GET['id'] ?? 0);
        $ruta = cre_ruta_zip($id);
        auditar($u['id'], 'ARCHIVOS_DESCARGAR', 'aud_ejecucion', $id, null, null);
        header_remove('Content-Type');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="CREACION ' . $id . ' - ARCHIVOS DE CARGUE.zip"');
        header('Content-Length: ' . filesize($ruta));
        readfile($ruta);
        exit;
    }],
    'sol_aprobaciones' => ['GET', function () {
        requerir_permiso('CATALOGO.EDITAR');
        return ['pendientes' => sol_aprobaciones(db()),
                'resueltas' => array_slice(array_merge(sol_aprobaciones(db(), 'APROBADA'), sol_aprobaciones(db(), 'RECHAZADA')), 0, 100)];
    }],
    'sol_resolver' => ['POST', function () {
        $u = requerir_permiso('CATALOGO.EDITAR');
        $in = entrada();
        return transaccion(fn() => sol_resolver(db(), (int) ($in['id'] ?? 0), !empty($in['aprobar']), is_array($in['ajustes'] ?? null) ? $in['ajustes'] : [],
            (string) ($in['motivo'] ?? ''), $u['id']));
    }],
    'nt_repetir_lineas' => ['POST', function () {
        $u = requerir_permiso('NT.GESTIONAR');
        return ['lineas' => nt_repetir_lineas(db(), $u['id'])];
    }],
    'nt_por_agregar' => ['GET', function () {
        requerir_permiso('NT.CONSULTAR');
        $filas = array_map(fn($f) => $f + ['contrato_vista' => nt_contrato_vista($f['contrato']), 'subplan' => nt_subplan_vista($f['contrato'])], nt_por_agregar(db()));
        return ['filas' => $filas, 'columnas' => nt_columnas_por_agregar()];
    }],
    'nt_por_agregar_plantilla' => ['GET', function () {
        $u = requerir_permiso('NT.GESTIONAR');
        $tmp = tempnam(sys_get_temp_dir(), 'nt');
        $nombre = nt_por_agregar_plantilla(db(), $tmp);
        auditar($u['id'], 'NT_PLANTILLA_AGREGAR', 'nt_item', null, null, ['archivo' => $nombre, 'filas' => count(nt_por_agregar(db()))]);
        header_remove('Content-Type');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        unlink($tmp);
        exit;
    }],
    'nt_activar_masivo' => ['POST', function () {
        $u = requerir_permiso('NT.GESTIONAR');
        $in = entrada();
        $filtros = !empty($in['todas_filtradas']) ? (is_array($in['filtros'] ?? null) ? $in['filtros'] : []) : null;
        // filas = «id de la línea|PLU» (estado por PLU + CONTRATO)
        return transaccion(fn() => nt_activar_masivo(db(), (array) ($in['filas'] ?? []), $filtros, !empty($in['activar']), (string) ($in['motivo'] ?? ''), $u['id']));
    }],
    'nt_guardar' => ['POST', function () {
        $u = requerir_permiso('NT.GESTIONAR');
        return transaccion(fn() => nt_guardar(db(), entrada(), $u['id']));
    }],
    'nt_sugerencia' => ['GET', function () {
        requerir_permiso('NT.GESTIONAR');
        return nt_sugerencia(db(), (string) ($_GET['cod_auf'] ?? ''), (string) ($_GET['cum_facturar'] ?? ''), (string) ($_GET['cod_mutual'] ?? ''));
    }],
    'nt_importar' => ['POST', function () {
        $u = requerir_permiso('NT.GESTIONAR');
        $a = $_FILES['archivo'] ?? [];
        if (empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) throw new ErrorApi('El archivo supera el tamaño que acepta el servidor.', 413);
        if (($a['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($a['tmp_name'] ?? '')) throw new ErrorApi('No se recibió el archivo.', 422);
        if ($a['size'] > (int) parametro('BASE_TAMANO_MAX_MB', '200') * 1024 * 1024) throw new ErrorApi('El archivo supera el tamaño permitido.', 413);
        return nt_importar(db(), $a['tmp_name'], basename((string) $a['name']), $u['id'], ($_POST['modo'] ?? '') === 'aplicar');
    }],
    'nt_exportar' => ['GET', function () {
        $u = requerir_permiso('NT.DESCARGAR');
        $tmp = tempnam(sys_get_temp_dir(), 'nt');
        $filtros = json_decode((string) ($_GET['filtros'] ?? '{}'), true);
        $filtros = nt_filtros_perfil($u, is_array($filtros) ? $filtros : []);
        $conPrecios = ver_precios($u);
        if (!$conPrecios) $filtros = nt_filtros_sin_precios($filtros);
        $nombre = nt_exportar(db(), $tmp, $filtros, !empty($_GET['errores']), $conPrecios);
        auditar($u['id'], 'NT_EXPORTAR', 'nt_item', null, null, ['archivo' => $nombre, 'filtros' => $filtros]);
        header_remove('Content-Type');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        unlink($tmp);
        exit;
    }],

    // ---------------------------------------------------------------- edición de catálogos (solo Administrador)
    'catalogo_sugerencias' => ['GET', function () {
        requerir_permiso('CATALOGO.EDITAR');
        return molecula_sugerencias(db()) + [
            'siguiente_laboratorio' => siguiente_codigo(db(), 'laboratorio'),
            'siguiente_presentacion' => siguiente_codigo(db(), 'presentacion'),
            'siguiente_medicar' => siguiente_codigo_medicar(db()),
            'medicar_repetidos' => codigos_medicar_repetidos(db()),
        ];
    }],
    'laboratorio_guardar' => ['POST', function () {
        $u = requerir_permiso('CATALOGO.EDITAR');
        return ['registro' => transaccion(fn() => laboratorio_guardar(db(), entrada(), $u['id']))];
    }],
    'presentacion_guardar' => ['POST', function () {
        $u = requerir_permiso('CATALOGO.EDITAR');
        return transaccion(fn() => presentacion_guardar(db(), entrada(), $u['id']));
    }],
    'catalogo_guardar' => ['POST', function () {
        $u = requerir_permiso('CATALOGO.EDITAR');
        $in = entrada();
        return ['registro' => transaccion(fn() => catalogo_guardar(db(), (string) ($in['tipo'] ?? ''), $in, $u['id']))];
    }],
    'listas' => ['GET', function () {
        requerir_login();
        return ['listas' => listas_estado(db())];
    }],
    'lista_crear' => ['POST', function () {
        $u = requerir_permiso('CATALOGO.EDITAR');
        return ['lista' => transaccion(fn() => lista_crear(db(), entrada(), $u['id']))];
    }],
    'lista_valor_guardar' => ['POST', function () {
        $u = requerir_permiso('CATALOGO.EDITAR');
        return ['valor' => transaccion(fn() => lista_valor_guardar(db(), entrada(), $u['id']))];
    }],
    'catalogo_plantilla' => ['GET', function () {
        requerir_permiso('CATALOGO.EDITAR');
        $tmp = tempnam(sys_get_temp_dir(), 'cat');
        $nombre = catalogo_plantilla(db(), (string) ($_GET['tipo'] ?? ''), $tmp);
        header_remove('Content-Type');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        unlink($tmp);
        exit;
    }],
    'catalogo_excel' => ['POST', function () {
        $u = requerir_permiso('CATALOGO.EDITAR');
        $a = $_FILES['archivo'] ?? [];
        if (($a['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($a['tmp_name'] ?? '')) throw new ErrorApi('No se recibió el archivo.', 422);
        if (strtolower(pathinfo((string) $a['name'], PATHINFO_EXTENSION)) !== 'xlsx') throw new ErrorApi('Use un archivo .xlsx (la plantilla descargada).', 422);
        if ($a['size'] > 30 * 1024 * 1024) throw new ErrorApi('El archivo supera 30 MB.', 413);
        return catalogo_procesar_excel(db(), (string) ($_POST['tipo'] ?? ''), $a['tmp_name'], basename((string) $a['name']), $u['id'], ($_POST['modo'] ?? '') === 'aplicar');
    }],
    'molecula_guardar' => ['POST', function () {
        $u = requerir_permiso('CATALOGO.EDITAR');
        $r = transaccion(fn() => molecula_guardar(db(), entrada(), $u['id']));
        cor_revisar_tras_cargue('MAESTRO_SIESA', $u['id']); // POS / NO POS y COD AUF cambian lo esperado en SIESA y MEDICAR
        return $r;
    }],

    // ---------------------------------------------------------------- catálogos (consulta paginada en servidor)
    'catalogo' => ['GET', function () {
        requerir_login();
        $tipo = $_GET['tipo'] ?? '';
        if (!isset(CATALOGOS[$tipo])) throw new ErrorApi('Catálogo no válido.', 404);
        [$tabla, $cols, $buscables, $orden] = CATALOGOS[$tipo];
        $pag = max(1, (int) ($_GET['pagina'] ?? 1));
        $tam = min(100, max(10, (int) ($_GET['tam'] ?? 25)));
        $columnas = array_map('trim', explode(',', $cols));
        $campo = $_GET['valores_de'] ?? null;
        if ($campo !== null && !in_array($campo, $columnas, true)) throw new ErrorApi('Columna no válida.', 422);
        [$cond, $params] = filtros_sql(array_combine($columnas, $columnas), ['activo', 'habilitado'], $campo);
        if ($tipo === 'lista') { // cada lista es un catálogo independiente
            $cond[] = 'lista = ?';
            $params[] = (string) ($_GET['lista'] ?? '');
        }
        if ($tipo === 'rips' && isset($_GET['tabla'])) { // TABLA-FFM, TABLA-UMM y TABLA-UPR son catálogos separados
            $cond[] = 'tabla = ?';
            $params[] = (string) $_GET['tabla'];
        }
        if ($campo !== null) return valores_columna($tabla, $campo, $cond, $params);
        $where = $cond ? 'WHERE ' . implode(' AND ', $cond) : '';
        $st = db()->prepare("SELECT COUNT(*) FROM $tabla $where");
        $st->execute($params);
        $total = (int) $st->fetchColumn();
        $st = db()->prepare("SELECT $cols FROM $tabla $where ORDER BY $orden LIMIT $tam OFFSET " . (($pag - 1) * $tam));
        $st->execute($params);
        return ['total' => $total, 'pagina' => $pag, 'tam' => $tam, 'filas' => $st->fetchAll()];
    }],
    'subplan_guardar' => ['POST', function () {
        $u = requerir_permiso('CATALOGO.EDITAR');
        $in = entrada();
        $id = (int) ($in['id_subplan'] ?? 0);
        if ($id <= 0) throw new ErrorApi('El ID del subplán debe ser un número mayor a 0.', 422);
        $pdo = db();
        $st = $pdo->prepare('SELECT * FROM cat_subplan WHERE id_subplan = ?');
        $st->execute([$id]);
        $antes = $st->fetch() ?: null;
        // Fase 7D: clasificación del contrato (CAPITA / EVENTO) y fuente de precio del subplán
        $clas = strtoupper(trim((string) ($in['clasificacion'] ?? '')));
        if ($clas !== '' && !in_array($clas, ['CAPITA', 'EVENTO'], true)) throw new ErrorApi('La clasificación debe ser CAPITA o EVENTO.', 422);
        $fuente = strtoupper(trim((string) ($in['fuente'] ?? '')));
        if ($fuente !== '' && !in_array($fuente, SUBPLAN_FUENTES, true)) throw new ErrorApi('Fuente de precio no válida.', 422);
        if ($antes) {
            // Como en Traslados: no se renombra (no deja huérfanos en el histórico); se activa/desactiva y se parametriza.
            $activo = !empty($in['activo']) ? 1 : 0;
            $pdo->prepare('UPDATE cat_subplan SET activo = ?, clasificacion = ?, fuente = ?, actualizado_en = ?, actualizado_por = ? WHERE id_subplan = ?')
                ->execute([$activo, $clas ?: null, $fuente ?: null, ahora(), $u['id'], $id]);
        } else {
            $nombre = mb_strtoupper(texto($in, 'nombre', 150, true));
            $st = $pdo->prepare('SELECT COUNT(*) FROM cat_subplan WHERE UPPER(nombre) = ?');
            $st->execute([$nombre]);
            if ($st->fetchColumn() > 0) throw new ErrorApi('Ya existe un subplán con ese nombre.', 409);
            insertar($pdo, 'cat_subplan', ['id_subplan' => $id, 'nombre' => $nombre, 'clasificacion' => $clas ?: null, 'fuente' => $fuente ?: null, 'activo' => 1, 'actualizado_en' => ahora(), 'actualizado_por' => $u['id']]);
        }
        $st = $pdo->prepare('SELECT * FROM cat_subplan WHERE id_subplan = ?');
        $st->execute([$id]);
        $despues = $st->fetch();
        auditar($u['id'], $antes ? 'SUBPLAN_ESTADO' : 'SUBPLAN_CREAR', 'cat_subplan', $id, $antes, $despues);
        return ['ok' => true, 'subplan' => $despues];
    }],

    // ---------------------------------------------------------------- usuarios
    'usuarios' => ['GET', function () {
        requerir_permiso('USUARIO.GESTIONAR');
        $filas = db()->query(
            "SELECT u.id, u.usuario, u.nombre, u.correo, u.activo, u.debe_cambiar_clave, u.ultimo_acceso, u.creado_en,
                    u.bloqueado_hasta
               FROM seg_usuario u ORDER BY u.nombre")->fetchAll();
        $pp = db()->query("SELECT up.usuario_id, p.codigo FROM seg_usuario_perfil up JOIN seg_perfil p ON p.id = up.perfil_id")->fetchAll();
        $map = [];
        foreach ($pp as $r) $map[$r['usuario_id']][] = $r['codigo'];
        foreach ($filas as &$f) $f['perfiles'] = $map[$f['id']] ?? [];
        return ['usuarios' => $filas, 'perfiles' => db()->query('SELECT id, codigo, nombre, activo FROM seg_perfil ORDER BY id')->fetchAll()];
    }],
    'usuario_guardar' => ['POST', function () {
        $admin = requerir_permiso('USUARIO.GESTIONAR');
        $in = entrada();
        $pdo = db();
        $id = (int) ($in['id'] ?? 0);
        $datos = [
            'nombre' => texto($in, 'nombre', 150, true),
            'correo' => texto($in, 'correo', 150),
            'activo' => !empty($in['activo']) ? 1 : 0,
        ];
        if ($datos['correo'] && !filter_var($datos['correo'], FILTER_VALIDATE_EMAIL)) throw new ErrorApi('Correo no válido.', 422);
        $perfiles = array_values(array_unique(array_map('strval', (array) ($in['perfiles'] ?? []))));
        $validos = $pdo->query('SELECT codigo, id FROM seg_perfil')->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($perfiles as $p) if (!isset($validos[$p])) throw new ErrorApi("Perfil no válido: $p", 422);
        if (!$perfiles) throw new ErrorApi('Asigne al menos un perfil.', 422);

        $pdo->beginTransaction();
        try {
            if ($id) {
                $st = $pdo->prepare('SELECT id, usuario, nombre, correo, activo FROM seg_usuario WHERE id = ?');
                $st->execute([$id]);
                $antes = $st->fetch();
                if (!$antes) throw new ErrorApi('Usuario no encontrado.', 404);
                $antes['perfiles'] = perfiles_de($id);
                if ($id === $admin['id'] && (!$datos['activo'] || !in_array('ADMIN', $perfiles, true))) {
                    throw new ErrorApi('No puede desactivarse ni quitarse el perfil Administrador a sí mismo.', 422);
                }
                $pdo->prepare('UPDATE seg_usuario SET nombre = ?, correo = ?, activo = ? WHERE id = ?')->execute([$datos['nombre'], $datos['correo'], $datos['activo'], $id]);
            } else {
                $usuario = mb_strtolower(texto($in, 'usuario', 80, true));
                if (!preg_match('/^[a-z0-9._@-]{3,80}$/', $usuario)) throw new ErrorApi('El usuario solo admite letras, números y . _ - @', 422);
                $st = $pdo->prepare('SELECT COUNT(*) FROM seg_usuario WHERE usuario = ?');
                $st->execute([$usuario]);
                if ($st->fetchColumn() > 0) throw new ErrorApi('Ese usuario ya existe.', 409);
                $clave = (string) ($in['clave_temporal'] ?? '');
                validar_clave_nueva($clave);
                $antes = null;
                $id = insertar($pdo, 'seg_usuario', $datos + [
                    'usuario' => $usuario, 'password_hash' => password_hash($clave, PASSWORD_DEFAULT),
                    'debe_cambiar_clave' => 1, 'intentos_fallidos' => 0, 'creado_en' => ahora(), 'creado_por' => $admin['id'],
                ]);
            }
            $actuales = perfiles_de($id);
            sort($actuales); sort($perfiles);
            if ($actuales !== $perfiles) {
                if (!in_array('PERFIL.ASIGNAR', permisos_de($admin['id']), true)) throw new ErrorApi('No tiene permiso para asignar perfiles.', 403);
                $pdo->prepare('DELETE FROM seg_usuario_perfil WHERE usuario_id = ?')->execute([$id]);
                foreach ($perfiles as $p) insertar($pdo, 'seg_usuario_perfil', ['usuario_id' => $id, 'perfil_id' => $validos[$p]]);
            }
            $activosAdmin = (int) $pdo->query("SELECT COUNT(*) FROM seg_usuario u JOIN seg_usuario_perfil up ON up.usuario_id = u.id JOIN seg_perfil p ON p.id = up.perfil_id WHERE p.codigo = 'ADMIN' AND u.activo = 1")->fetchColumn();
            if ($activosAdmin === 0) throw new ErrorApi('Debe quedar al menos un Administrador activo.', 422);
            auditar($admin['id'], $antes ? 'USUARIO_EDITAR' : 'USUARIO_CREAR', 'seg_usuario', $id, $antes, $datos + ['perfiles' => $perfiles]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['ok' => true, 'id' => $id];
    }],
    'usuario_restablecer_clave' => ['POST', function () {
        $admin = requerir_permiso('USUARIO.GESTIONAR');
        $in = entrada();
        $id = (int) ($in['id'] ?? 0);
        $clave = (string) ($in['clave_temporal'] ?? '');
        validar_clave_nueva($clave);
        $st = db()->prepare('UPDATE seg_usuario SET password_hash = ?, debe_cambiar_clave = 1, intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = ?');
        $st->execute([password_hash($clave, PASSWORD_DEFAULT), $id]);
        if (!$st->rowCount()) throw new ErrorApi('Usuario no encontrado.', 404);
        auditar($admin['id'], 'USUARIO_RESTABLECER_CLAVE', 'seg_usuario', $id);
        return ['ok' => true];
    }],

    // ---------------------------------------------------------------- perfiles y permisos
    // Reglas de validación: el Administrador crea reglas personalizadas y activa / inactiva cualquier regla
    'reglas_config' => ['GET', function () {
        requerir_permiso('PARAMETRO.ADMINISTRAR');
        return ['campos' => regla_campos(), 'condiciones' => array_map(fn($c) => ['nombre' => $c[0], 'valor' => $c[1]], REGLA_CONDICIONES)];
    }],
    'regla_guardar' => ['POST', function () {
        $u = requerir_permiso('PARAMETRO.ADMINISTRAR');
        return ['regla' => regla_guardar(db(), entrada(), $u['id'])];
    }],
    'regla_activar' => ['POST', function () {
        $u = requerir_permiso('PARAMETRO.ADMINISTRAR');
        $in = entrada();
        return regla_activar(db(), (string) ($in['codigo'] ?? ''), !empty($in['activa']), $u['id']);
    }],
    'perfiles' => ['GET', function () {
        requerir_permiso('PARAMETRO.ADMINISTRAR'); // solo quien administra perfiles ve la matriz (antes bastaba iniciar sesión)
        $pdo = db();
        return [
            'modulos'  => $pdo->query('SELECT id, codigo, nombre, estado FROM seg_modulo ORDER BY orden')->fetchAll(),
            'permisos' => $pdo->query('SELECT p.id, p.codigo, p.nombre, m.codigo AS modulo FROM seg_permiso p JOIN seg_modulo m ON m.id = p.modulo_id ORDER BY m.orden, p.id')->fetchAll(),
            'perfiles' => $pdo->query('SELECT id, codigo, nombre, descripcion, activo FROM seg_perfil ORDER BY id')->fetchAll(),
            'asignados'=> $pdo->query('SELECT pp.perfil_id, pe.codigo FROM seg_perfil_permiso pp JOIN seg_permiso pe ON pe.id = pp.permiso_id')->fetchAll(),
            'solo_admin' => PERMISOS_SOLO_ADMIN,
        ];
    }],
    'perfil_permisos_guardar' => ['POST', function () {
        $u = requerir_permiso('PARAMETRO.ADMINISTRAR');
        $in = entrada();
        $pdo = db();
        $pid = (int) ($in['perfil_id'] ?? 0);
        $st = $pdo->prepare('SELECT codigo FROM seg_perfil WHERE id = ?');
        $st->execute([$pid]);
        $codigo = $st->fetchColumn();
        if (!$codigo) throw new ErrorApi('Perfil no encontrado.', 404);
        $nuevos = array_values(array_unique(array_map('strval', (array) ($in['permisos'] ?? []))));
        if ($codigo === 'ADMIN') {
            foreach (['USUARIO.GESTIONAR', 'PERFIL.ASIGNAR', 'PARAMETRO.ADMINISTRAR'] as $imprescindible) {
                if (!in_array($imprescindible, $nuevos, true)) throw new ErrorApi("El perfil Administrador no puede perder $imprescindible.", 422);
            }
        }
        $ids = $pdo->query('SELECT codigo, id FROM seg_permiso')->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($nuevos as $p) if (!isset($ids[$p])) throw new ErrorApi("Permiso no válido: $p", 422);
        if ($codigo !== 'ADMIN' && ($prohibidos = array_intersect($nuevos, PERMISOS_SOLO_ADMIN))) {
            throw new ErrorApi('Estos permisos son exclusivos del Administrador: ' . implode(', ', $prohibidos), 422);
        }
        $st = $pdo->prepare('SELECT pe.codigo FROM seg_perfil_permiso pp JOIN seg_permiso pe ON pe.id = pp.permiso_id WHERE pp.perfil_id = ?');
        $st->execute([$pid]);
        $antes = $st->fetchAll(PDO::FETCH_COLUMN);
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM seg_perfil_permiso WHERE perfil_id = ?')->execute([$pid]);
        foreach ($nuevos as $p) insertar($pdo, 'seg_perfil_permiso', ['perfil_id' => $pid, 'permiso_id' => $ids[$p]]);
        auditar($u['id'], 'PERFIL_PERMISOS', 'seg_perfil', $pid, $antes, $nuevos);
        $pdo->commit();
        return ['ok' => true];
    }],

    // ---------------------------------------------------------------- parámetros
    'parametros' => ['GET', function () {
        requerir_permiso('PARAMETRO.ADMINISTRAR');
        return ['parametros' => db()->query('SELECT p.clave, p.valor, p.descripcion, p.actualizado_en, u.nombre AS actualizado_por FROM cat_parametro p LEFT JOIN seg_usuario u ON u.id = p.actualizado_por ORDER BY p.clave')->fetchAll()];
    }],
    'parametro_guardar' => ['POST', function () {
        $u = requerir_permiso('PARAMETRO.ADMINISTRAR');
        $in = entrada();
        $clave = texto($in, 'clave', 60, true);
        $valor = texto($in, 'valor', 500, true);
        $st = db()->prepare('SELECT valor FROM cat_parametro WHERE clave = ?');
        $st->execute([$clave]);
        $antes = $st->fetchColumn();
        if ($antes === false) throw new ErrorApi('Parámetro no encontrado.', 404);
        db()->prepare('UPDATE cat_parametro SET valor = ?, actualizado_en = ?, actualizado_por = ? WHERE clave = ?')->execute([$valor, ahora(), $u['id'], $clave]);
        auditar($u['id'], 'PARAMETRO_EDITAR', 'cat_parametro', $clave, $antes, $valor);
        return ['ok' => true];
    }],

    // ---------------------------------------------------------------- auditoría
    // ---------------- Correcciones SIESA / MEDICAR
    'cor_listar' => ['GET', function () {
        $u = requerir_permiso('CORRECCION.CONSULTAR');
        $sistema = cor_sistema();
        $cols = ['estado' => 'estado', 'plu' => 'plu', 'item' => 'item', 'cum_facturar' => 'cum_facturar', 'contratos' => 'contratos', 'subplan' => 'subplan', 'campo' => 'campo',
            'valor_actual' => 'valor_actual', 'valor_esperado' => 'valor_esperado', 'accion' => 'accion', 'cargue' => 'cargue',
            'detectado_en' => 'detectado_en', 'exportado_en' => 'exportado_en', 'cerrado_en' => 'cerrado_en'];
        $campo = $_GET['valores_de'] ?? null;
        if ($campo !== null && !isset($cols[$campo])) throw new ErrorApi('Columna no válida.', 422);
        [$cond, $params] = filtros_sql($cols, [], $campo);
        array_unshift($cond, 'sistema = ?'); array_unshift($params, $sistema);
        // lo corregido se conserva (trazabilidad) pero no se ve con lo pendiente: solo filtrando por el estado Corregido
        $filtros = json_decode((string) ($_GET['filtros'] ?? '{}'), true);
        if ($campo !== 'estado' && empty($filtros['estado'])) $cond[] = "estado NOT IN ('CORREGIDO', 'APROBADO')"; // cerrados: solo filtrando por su estado
        if (!ver_precios($u)) $cond[] = "campo <> 'PRECIO'"; // el precio de MEDICAR solo con PRECIO.VER
        if ($campo !== null) return valores_columna('cor_inconsistencia', $cols[$campo], $cond, $params);
        $pag = max(1, (int) ($_GET['pagina'] ?? 1));
        $where = 'WHERE ' . implode(' AND ', $cond);
        $st = db()->prepare("SELECT COUNT(*) FROM cor_inconsistencia $where");
        $st->execute($params);
        $total = (int) $st->fetchColumn();
        // primero lo que hay que hacer; lo corregido al final
        $orden = "CASE estado WHEN 'NO_VALIDADO' THEN 1 WHEN 'PENDIENTE_CORRECCION' THEN 2 WHEN 'PENDIENTE_REVISION' THEN 3 WHEN 'PENDIENTE_ACTUALIZACION' THEN 4 ELSE 5 END";
        $st = db()->prepare("SELECT id, estado, plu, item, cum_facturar, contratos, subplan, campo, valor_actual, valor_esperado, accion, cargue, detectado_en, exportado_en, cerrado_en
                              FROM cor_inconsistencia $where ORDER BY $orden, cum_facturar, plu, campo LIMIT 100 OFFSET " . (($pag - 1) * 100));
        $st->execute($params);
        return ['total' => $total, 'pagina' => $pag, 'tam' => 100, 'filas' => $st->fetchAll(), 'resumen' => cor_resumen(db(), $sistema),
                'campos' => array_map(fn($c) => ['nombre' => $c[0], 'cargue' => $c[1]], cor_campos($sistema)), 'estados' => COR_ESTADOS];
    }],
    'cor_revisar' => ['POST', function () {
        $u = requerir_permiso('CORRECCION.GESTIONAR');
        return transaccion(fn() => cor_revisar(db(), cor_sistema((string) (entrada()['sistema'] ?? '')), $u['id']));
    }],
    // CUM que sí deben tener varios PLU activos en SIESA (2026-11-05)
    'cor_aprobar' => ['POST', function () {
        $u = requerir_permiso('NT.GESTIONAR'); // se aprueba en la Maestra Tarifaria (NT29)
        $in = entrada();
        return transaccion(fn() => cor_aprobar_varios(db(), (string) ($in['cum_facturar'] ?? ''), (string) ($in['motivo'] ?? ''), $u['id']));
    }],
    'cor_aprobacion_quitar' => ['POST', function () {
        $u = requerir_permiso('NT.GESTIONAR');
        return transaccion(fn() => cor_quitar_aprobacion(db(), (int) (entrada()['id'] ?? 0), $u['id']));
    }],
    'cor_aprobaciones' => ['GET', function () {
        requerir_permiso('NT.CONSULTAR');
        return ['aprobaciones' => cor_aprobaciones(db())];
    }],
    'cor_exportar' => ['POST', function () {
        $u = requerir_permiso('CORRECCION.GESTIONAR');
        $in = entrada();
        $sistema = cor_sistema((string) ($in['sistema'] ?? ''));
        $_GET['filtros'] = json_encode(is_array($in['filtros'] ?? null) ? $in['filtros'] : []); // los mismos filtros de la tabla
        [$cond, $params] = filtros_sql(['estado' => 'estado', 'plu' => 'plu', 'item' => 'item', 'cum_facturar' => 'cum_facturar', 'contratos' => 'contratos',
            'subplan' => 'subplan', 'campo' => 'campo', 'valor_actual' => 'valor_actual', 'valor_esperado' => 'valor_esperado', 'accion' => 'accion', 'cargue' => 'cargue']);
        return transaccion(fn() => $sistema === 'MEDICAR' ? cor_exportar_medicar(db(), $cond, $params, $u['id']) : cor_exportar_siesa(db(), $cond, $params, $u['id']));
    }],
    'cor_exportaciones' => ['GET', function () {
        requerir_permiso('CORRECCION.GESTIONAR');
        return ['exportaciones' => cor_exportaciones(db(), cor_sistema())];
    }],
    'cor_descargar' => ['GET', function () {
        $u = requerir_permiso('CORRECCION.GESTIONAR');
        $id = (int) ($_GET['id'] ?? 0);
        [$ruta, $sistema] = cor_ruta_zip(db(), $id);
        auditar($u['id'], 'COR_DESCARGAR', 'cor_exportacion', $id, null, null);
        if (str_ends_with($ruta, '.xlsx')) enviar_archivo($ruta, basename($ruta)); // MEDICAR: un Excel (PLU + OBSERVACION)
        header_remove('Content-Type');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="CORRECCIONES ' . $sistema . ' ' . $id . ' - ARCHIVOS DE CARGUE.zip"');
        header('Content-Length: ' . filesize($ruta));
        readfile($ruta);
        exit;
    }],
    'auditoria_exportar' => ['GET', function () {
        $u = requerir_permiso('AUDITORIA.CONSULTAR');
        $cols = ['creado_en' => 'l.creado_en', 'usuario' => 'u.usuario', 'accion' => 'l.accion', 'entidad' => 'l.entidad',
                 'entidad_id' => 'l.entidad_id', 'antes' => 'l.antes', 'despues' => 'l.despues', 'ip' => 'l.ip'];
        [$cond, $params] = filtros_sql($cols);
        $where = $cond ? 'WHERE ' . implode(' AND ', $cond) : '';
        $filas = (function () use ($where, $params) {
            $st = db()->prepare("SELECT l.creado_en, u.usuario, l.accion, l.entidad, l.entidad_id, l.antes, l.despues, l.ip FROM aud_log l
                                  LEFT JOIN seg_usuario u ON u.id = l.usuario_id $where ORDER BY l.id DESC");
            $st->execute($params);
            foreach ($st as $r) foreach (aud_expandir($r['antes'], $r['despues']) as [$campo, $antes, $despues])
                yield [$r['creado_en'], $r['usuario'], $r['accion'], $r['entidad'], $r['entidad_id'], $campo,
                       $antes === null ? null : mb_substr($antes, 0, 32000), $despues === null ? null : mb_substr($despues, 0, 32000), $r['ip']];
        })();
        $tmp = tempnam(sys_get_temp_dir(), 'aud');
        XlsxWriter::guardar($tmp, [['nombre' => 'AUDITORIA', 'encabezados' => ['FECHA', 'USUARIO', 'ACCIÓN', 'ENTIDAD', 'ID', 'CAMPO', 'ANTES', 'DESPUÉS', 'IP'], 'filas' => $filas]]);
        auditar($u['id'], 'AUDITORIA_EXPORTAR', 'aud_log', null, null, ['filtros' => $_GET['filtros'] ?? null]);
        enviar_archivo($tmp, 'AUDITORIA - ' . date('Ymd_His') . '.xlsx', true);
    }],
    'auditoria' => ['GET', function () {
        requerir_permiso('AUDITORIA.CONSULTAR');
        $pag = max(1, (int) ($_GET['pagina'] ?? 1));
        $cols = [
            'creado_en' => 'l.creado_en', 'usuario' => 'u.usuario', 'accion' => 'l.accion', 'entidad' => "l.entidad",
            'entidad_id' => 'l.entidad_id', 'antes' => 'l.antes', 'despues' => 'l.despues', 'ip' => 'l.ip',
        ];
        $campo = $_GET['valores_de'] ?? null;
        if ($campo !== null && !isset($cols[$campo])) throw new ErrorApi('Columna no válida.', 422);
        [$cond, $params] = filtros_sql($cols, [], $campo);
        if ($campo !== null) return valores_columna('aud_log l LEFT JOIN seg_usuario u ON u.id = l.usuario_id', $cols[$campo], $cond, $params);
        $where = $cond ? 'WHERE ' . implode(' AND ', $cond) : '';
        $st = db()->prepare("SELECT COUNT(*) FROM aud_log l LEFT JOIN seg_usuario u ON u.id = l.usuario_id $where");
        $st->execute($params);
        $total = (int) $st->fetchColumn();
        $st = db()->prepare("SELECT l.creado_en, u.usuario, l.accion, l.entidad, l.entidad_id, l.antes, l.despues, l.ip FROM aud_log l LEFT JOIN seg_usuario u ON u.id = l.usuario_id $where ORDER BY l.id DESC LIMIT 50 OFFSET " . (($pag - 1) * 50));
        $st->execute($params);
        return ['total' => $total, 'pagina' => $pag, 'tam' => 50, 'filas' => $st->fetchAll()];
    }],
];

// ------------------------------------------------------------------ despacho
try {
    $nombre = $_GET['accion'] ?? '';
    if (!in_array($nombre, ACCIONES_CON_LLAVE, true)) iniciar_sesion_php(); // las acciones con llave de API no usan sesión ni cookies
    if (!isset($acciones[$nombre])) throw new ErrorApi('Acción no encontrada.', 404);
    [$metodo, $fn] = $acciones[$nombre];
    if ($_SERVER['REQUEST_METHOD'] !== $metodo) throw new ErrorApi('Método no permitido.', 405);
    if ($metodo === 'POST') verificar_csrf();
    // Foto diaria de la Maestra Tarifaria antes del primer cambio del día (ver nt_foto_del_dia)
    // (solo si el usuario puede cambiarla; las aprobaciones también crean líneas en la Maestra Tarifaria)
    if ($metodo === 'POST' && in_array($nombre, ['nt_quitar_medicar', 'nt_validar_cum', 'nt_precio_uno_aprobar', 'nt_repetir_lineas', 'nt_guardar', 'nt_importar', 'nt_activar_masivo', 'sol_resolver'], true)
        && ($ua = usuario_actual()) && array_intersect(['NT.GESTIONAR', 'CATALOGO.EDITAR'], permisos_de((int) $ua['id']))) {
        try { nt_foto_del_dia(db(), (int) $ua['id']); } catch (Throwable $e) { error_log('[nt_foto_del_dia] ' . $e); }
    }
    responder($fn());
} catch (ErrorApi $e) {
    responder(['error' => $e->getMessage()], $e->http);
} catch (Throwable $e) {
    error_log('[creacion_items] ' . $e);
    // No se exponen detalles internos (rutas, SQL) al usuario.
    responder(['error' => 'Error interno del servidor. Consulte al administrador.'], 500);
}
