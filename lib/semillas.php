<?php
/**
 * SEMILLAS — datos iniciales de seguridad, reglas, parámetros y catálogos fijos.
 * Seguro de re-ejecutar: lo que el administrador ya modificó no se sobrescribe
 * (insertar_si_no_existe), salvo la definición de módulos y permisos.
 */

function semilla_seguridad(PDO $pdo): void {
    $modulos = [
        ['PRODUCTOS',    'Creación y consulta de productos', 10],
        ['NOTA_TECNICA', 'Maestra Tarifaria',                     20],
        ['PRECIOS',      'Asignación de precios',            30],
        ['BASES',        'Bases de referencia',              40],
        ['SEGURIDAD',    'Usuarios y perfiles',              50],
        ['PARAMETROS',   'Parametrización',                  60],
        ['AUDITORIA',    'Auditoría',                        70],
        ['CORRECCIONES', 'Inconsistencias SIESA y MEDICAR',  25],
    ];
    foreach ($modulos as [$c, $n, $o]) upsert($pdo, 'seg_modulo', ['codigo' => $c, 'nombre' => $n, 'orden' => $o], ['codigo']);

    $modId = fn($c) => (int) $pdo->query("SELECT id FROM seg_modulo WHERE codigo = " . $pdo->quote($c))->fetchColumn();
    $permisos = [
        ['PRODUCTOS',    'PRODUCTO.CREAR',            'Crear productos'],
        ['PRODUCTOS',    'PRODUCTO.CONSULTAR',        'Consultar productos creados'],
        ['PRODUCTOS',    'PRODUCTO.DESCARGAR',        'Descargar productos creados y archivos de cargue'],
        ['NOTA_TECNICA', 'NT.CONSULTAR',              'Consultar la Maestra Tarifaria'],
        ['NOTA_TECNICA', 'NT.DESCARGAR',              'Descargar la Maestra Tarifaria'],
        ['NOTA_TECNICA', 'NT.GESTIONAR',              'Gestionar la Maestra Tarifaria: crear, editar, importar y calcular precios'],
        ['NOTA_TECNICA', 'NT.VER_FUERA_CONTRATO',     'Ver las líneas de la Maestra Tarifaria que no están en el contrato'],
        ['PRECIOS',      'PRECIO.VER',                'Ver precios y tarifas (Maestra Tarifaria, bases con tarifas y sus descargas)'],
        ['PRECIOS',      'PRECIO.ASIGNAR',            'Asignar precios'],
        ['PRECIOS',      'PRECIO.APROBAR_EXCEPCION',  'Aprobar excepción de precio 1'],
        ['BASES',        'BASE.ACTUALIZAR',           'Cargar / actualizar bases de referencia'],
        ['SEGURIDAD',    'USUARIO.GESTIONAR',         'Crear y administrar usuarios'],
        ['SEGURIDAD',    'PERFIL.ASIGNAR',            'Asignar perfiles a usuarios'],
        ['PARAMETROS',   'PARAMETRO.ADMINISTRAR',     'Administrar parametrizaciones y catálogos'],
        ['PARAMETROS',   'CATALOGO.EDITAR',           'Crear y editar laboratorios, presentaciones y COD GEN'],
        ['AUDITORIA',    'AUDITORIA.CONSULTAR',       'Consultar auditoría'],
        ['CORRECCIONES', 'CORRECCION.CONSULTAR',      'Consultar las correcciones de SIESA y MEDICAR'],
        ['CORRECCIONES', 'CORRECCION.GESTIONAR',      'Revisar SIESA y MEDICAR y descargar los archivos de corrección'],
    ];
    foreach ($permisos as [$m, $c, $n]) upsert($pdo, 'seg_permiso', ['modulo_id' => $modId($m), 'codigo' => $c, 'nombre' => $n], ['codigo']);

    // Matriz inicial de permisos (punto 13.5). El cargue de bases externas es SOLO del Administrador
    // (decisión del usuario, 2026-09-25); los demás perfiles consultan su estado.
    $perfiles = [
        'CREACION' => ['Usuario de creación', 'Crea y gestiona inicialmente los productos',
            ['PRODUCTO.CREAR', 'PRODUCTO.CONSULTAR', 'PRODUCTO.DESCARGAR', 'NT.CONSULTAR', 'NT.DESCARGAR', 'PRECIO.VER', 'CORRECCION.CONSULTAR', 'NT.VER_FUERA_CONTRATO']],
        'CONSULTA' => ['Usuario de consulta', 'Solo consulta y descarga',
            ['PRODUCTO.CONSULTAR', 'PRODUCTO.DESCARGAR', 'NT.CONSULTAR', 'NT.DESCARGAR', 'PRECIO.VER', 'CORRECCION.CONSULTAR']],
        // para compartir con externos (decisión del usuario, 2026-10-14): consulta y descarga SIN precios ni tarifas
        'CONSULTA_SP' => ['Usuario de consulta sin precios', 'Consulta y descarga sin precios ni tarifas (para compartir con externos)',
            ['PRODUCTO.CONSULTAR', 'NT.CONSULTAR', 'NT.DESCARGAR']],
        'ADMIN'    => ['Administrador', 'Administración y parametrización',
            ['PRODUCTO.CREAR', 'PRODUCTO.CONSULTAR', 'PRODUCTO.DESCARGAR', 'NT.CONSULTAR', 'NT.DESCARGAR', 'NT.GESTIONAR', 'PRECIO.VER',
             'PRECIO.ASIGNAR', 'PRECIO.APROBAR_EXCEPCION', 'BASE.ACTUALIZAR', 'USUARIO.GESTIONAR', 'PERFIL.ASIGNAR',
             'PARAMETRO.ADMINISTRAR', 'CATALOGO.EDITAR', 'AUDITORIA.CONSULTAR', 'CORRECCION.CONSULTAR', 'CORRECCION.GESTIONAR', 'NT.VER_FUERA_CONTRATO']],
    ];
    foreach ($perfiles as $codigo => [$nombre, $desc, $lista]) {
        $nuevo = insertar_si_no_existe($pdo, 'seg_perfil', ['codigo' => $codigo, 'nombre' => $nombre, 'descripcion' => $desc, 'activo' => 1], ['codigo']);
        if (!$nuevo) continue; // si ya existía, se respetan los permisos que haya ajustado el administrador
        $pid = (int) $pdo->query("SELECT id FROM seg_perfil WHERE codigo = " . $pdo->quote($codigo))->fetchColumn();
        foreach ($lista as $p) {
            $perm = (int) $pdo->query("SELECT id FROM seg_permiso WHERE codigo = " . $pdo->quote($p))->fetchColumn();
            insertar($pdo, 'seg_perfil_permiso', ['perfil_id' => $pid, 'permiso_id' => $perm]);
        }
    }
}

/**
 * Cambios de datos que deben aplicarse UNA sola vez sobre bases ya instaladas (ajustes de
 * decisiones del usuario). Quedan registrados en sis_migracion y en auditoría.
 */
function migraciones_datos(PDO $pdo): array {
    $migraciones = [
        'M001_BASES_SOLO_ADMIN' => ['El cargue de bases externas queda solo en el perfil Administrador', function (PDO $pdo) {
            $pdo->exec("DELETE FROM seg_perfil_permiso
                         WHERE permiso_id = (SELECT id FROM seg_permiso WHERE codigo = 'BASE.ACTUALIZAR')
                           AND perfil_id IN (SELECT id FROM seg_perfil WHERE codigo <> 'ADMIN')");
        }],
        'M002_CATALOGO_EDITAR_ADMIN' => ['Permiso CATALOGO.EDITAR asignado solo al perfil Administrador', function (PDO $pdo) {
            $perm = (int) $pdo->query("SELECT id FROM seg_permiso WHERE codigo = 'CATALOGO.EDITAR'")->fetchColumn();
            $admin = (int) $pdo->query("SELECT id FROM seg_perfil WHERE codigo = 'ADMIN'")->fetchColumn();
            $pdo->exec("DELETE FROM seg_perfil_permiso WHERE permiso_id = $perm");
            insertar($pdo, 'seg_perfil_permiso', ['perfil_id' => $admin, 'permiso_id' => $perm]);
        }],
        'M003_LABORATORIOS_PRIMER_CODIGO' => ['Laboratorios con dos códigos: se conserva el primero, tal como estaba en el Excel (decisión del usuario 2026-09-25)', function (PDO $pdo) {
            registrar_decisiones_carga($pdo, 'cat_laboratorio', '/tiene dos códigos/',
                'Se conserva el primer código, tal como estaba en el Excel (decisión del usuario, 2026-09-25).');
            registrar_decisiones_carga($pdo, 'cat_presentacion', '/^AVISO/',
                'Informativo: cada presentación conserva su propio código (mejora C1 documentada).');
        }],
        'M005_COD_GEN_D1_D2' => ['D1: forma farmacéutica "." -> valor anterior (o NO APLICA); D2: INYACTABLE -> INYECTABLE (aprobado por el usuario 2026-09-25)', function (PDO $pdo) {
            // Valor anterior de cada registro según la auditoría del cargue por Excel (el más reciente).
            $anterior = [];
            foreach ($pdo->query("SELECT entidad_id, antes, despues FROM aud_log WHERE accion = 'CATALOGO_EXCEL_EDITAR' AND entidad = 'cat_molecula' ORDER BY id") as $a) {
                $d = json_decode((string) $a['despues'], true) ?: [];
                if (($d['forma_farmaceutica'] ?? null) === '.') $anterior[(int) $a['entidad_id']] = (json_decode((string) $a['antes'], true) ?: [])['forma_farmaceutica'] ?? null;
            }
            $upd = $pdo->prepare('UPDATE cat_molecula SET forma_farmaceutica = ?, actualizado_en = ? WHERE id = ?');
            $filas = $pdo->query("SELECT id, cod_gen_audifarma, forma_farmaceutica FROM cat_molecula WHERE forma_farmaceutica = '.' OR forma_farmaceutica LIKE '%INYACTABLE%'")->fetchAll();
            foreach ($filas as $f) {
                if ($f['forma_farmaceutica'] === '.') {
                    $previo = $anterior[(int) $f['id']] ?? null;
                    $nuevo = ($previo !== null && preg_match('/[\p{L}\p{N}]/u', $previo)) ? str_replace('INYACTABLE', 'INYECTABLE', $previo) : 'NO APLICA';
                } else {
                    $nuevo = str_replace('INYACTABLE', 'INYECTABLE', $f['forma_farmaceutica']);
                }
                $upd->execute([$nuevo, ahora(), $f['id']]);
                auditar(null, 'CORRECCION_DATOS', 'cat_molecula', $f['id'],
                    ['cod_gen_audifarma' => $f['cod_gen_audifarma'], 'forma_farmaceutica' => $f['forma_farmaceutica']],
                    ['forma_farmaceutica' => $nuevo, 'motivo' => 'M005 (D1/D2)']);
            }
        }],
        'M006_FORMA_NA_A_NO_APLICA' => ['D1 (complemento): forma farmacéutica NA / N/A -> NO APLICA', function (PDO $pdo) {
            foreach ($pdo->query("SELECT id, cod_gen_audifarma, forma_farmaceutica FROM cat_molecula WHERE forma_farmaceutica IN ('NA', 'N/A')")->fetchAll() as $f) {
                $pdo->prepare("UPDATE cat_molecula SET forma_farmaceutica = 'NO APLICA', actualizado_en = ? WHERE id = ?")->execute([ahora(), $f['id']]);
                auditar(null, 'CORRECCION_DATOS', 'cat_molecula', $f['id'], ['cod_gen_audifarma' => $f['cod_gen_audifarma'], 'forma_farmaceutica' => $f['forma_farmaceutica']],
                    ['forma_farmaceutica' => 'NO APLICA', 'motivo' => 'M006 (D1)']);
            }
        }],
        'M007_COD_GEN_D4' => ['D4: concentración, grupo farmacológico y vía con solo signos (".") -> NO APLICA (aprobado por el usuario 2026-09-25)', function (PDO $pdo) {
            foreach (['concentracion_agrupada', 'grupo_farmacologico', 'via_administracion', 'principio_activo', 'forma_farmaceutica'] as $campo) {
                foreach ($pdo->query("SELECT id, cod_gen_audifarma, $campo AS v FROM cat_molecula")->fetchAll() as $f) {
                    if ($f['v'] === null || $f['v'] === '' || preg_match('/[\p{L}\p{N}]/u', $f['v'])) continue;
                    $pdo->prepare("UPDATE cat_molecula SET $campo = 'NO APLICA', actualizado_en = ? WHERE id = ?")->execute([ahora(), $f['id']]);
                    auditar(null, 'CORRECCION_DATOS', 'cat_molecula', $f['id'], ['cod_gen_audifarma' => $f['cod_gen_audifarma'], $campo => $f['v']],
                        [$campo => 'NO APLICA', 'motivo' => 'M007 (D4)']);
                }
            }
        }],
        'M008_FASE3_TABLAS_BASES' => ['Fase 3: tablas de lectura por base (reemplazan ref_maestro_siesa y ref_listado_capita, vacías) y días de alerta por base', function (PDO $pdo) {
            foreach (['ref_maestro_siesa', 'ref_listado_capita'] as $t) {
                try { $st = $pdo->query("SELECT COUNT(*) FROM $t"); $n = (int) $st->fetchColumn(); $st->closeCursor(); $st = null; } catch (Throwable) { continue; } // no existe
                // (la consulta se cierra antes del DROP: SQLite no elimina una tabla con una lectura abierta)
                if ($n === 0) $pdo->exec("DROP TABLE $t");
            }
            foreach (['MAESTRO_SIESA' => 7, 'MAESTRA_MEDICAR' => 7, 'INVIMA' => 7, 'NOTA_TECNICA' => 30,
                      'LISTADO_CAPITA' => 30, 'LISTADO_EVENTO_SAS' => 30, 'CONTRATO_EVENTO_22382' => 30] as $c => $dias) {
                $pdo->prepare('UPDATE ref_base SET dias_alerta = ? WHERE codigo = ?')->execute([$dias, $c]);
            }
        }],
        'M009_FASE4_NOTA_TECNICA' => ['Fase 4: la Maestra Tarifaria se lee a ref_nt_item (se retiran nt_version / nt_item, vacías) y días de alerta de las bases nuevas', function (PDO $pdo) {
            $vacia = function (string $t) use ($pdo): ?bool {
                try { $st = $pdo->query("SELECT COUNT(*) FROM $t"); $n = (int) $st->fetchColumn(); $st->closeCursor(); return $n === 0; }
                catch (Throwable) { return null; } // no existe
            };
            // nt_producto apunta a nt_item: se recrea apuntando a ref_nt_item (solo si todo está vacío).
            if (columna_existe($pdo, 'nt_item', 'version_id') && $vacia('nt_producto') && $vacia('nt_item') && $vacia('nt_version') !== false) {
                $pdo->exec('DROP TABLE nt_producto');
                foreach (['nt_item', 'nt_version'] as $t) if ($vacia($t) !== null) $pdo->exec("DROP TABLE $t");
                crear_tabla($pdo, 'nt_producto');
            }
            foreach (['NOTA_TECNICA' => 30, 'CUMS_MUTUAL' => 30, 'PRECIOS_REGULADOS' => 365] as $c => $dias) {
                $pdo->prepare('UPDATE ref_base SET dias_alerta = ? WHERE codigo = ?')->execute([$dias, $c]);
            }
        }],
        'M010_NT_EN_APLICATIVO' => ['Fase 4: la Maestra Tarifaria se mantiene en el aplicativo (nt_item); sale de Bases de referencia', function (PDO $pdo) {
            $pdo->exec("UPDATE ref_base SET activo = 0 WHERE codigo = 'NOTA_TECNICA'");
            $pdo->exec("UPDATE ref_base SET dias_alerta = 30 WHERE codigo = 'MEDICAMENTOS_MUTUAL'");
            // nt_producto quedó apuntando a ref_nt_item (área de importación): se recrea hacia nt_item si está vacía.
            $st = $pdo->query('SELECT COUNT(*) FROM nt_producto'); $n = (int) $st->fetchColumn(); $st->closeCursor(); $st = null;
            if ($n === 0) { $pdo->exec('DROP TABLE nt_producto'); crear_tabla($pdo, 'nt_producto'); }
        }],
        'M011_MAESTRA_MUTUAL_UNICA' => ['Fase 4: una sola base de Mutual Ser (Maestra Mutual, automática); se retira la homologación CUMS_PHARMASER y la regla NT15', function (PDO $pdo) {
            $pdo->exec("UPDATE ref_base SET activo = 0 WHERE codigo = 'CUMS_MUTUAL'");
            $pdo->exec("DELETE FROM proc_regla WHERE codigo = 'NT15'");
            $pdo->exec("UPDATE proc_regla SET base_comparada = 'Maestra Mutual Ser' WHERE codigo IN ('NT05', 'NT07')");
            $pdo->exec("UPDATE ref_base SET dias_alerta = 7 WHERE codigo = 'MEDICAMENTOS_MUTUAL'");
            // Cargas manuales previas de la consulta de medicamentos: la base ahora la llena la descarga automática.
            $pdo->exec("DELETE FROM ref_medicamentos_mutual");
            $pdo->exec("UPDATE ref_carga SET vigente = 0, estado = 'REEMPLAZADA' WHERE tipo_base IN ('MEDICAMENTOS_MUTUAL', 'CUMS_MUTUAL') AND vigente = 1");
            $pdo->exec("DELETE FROM ref_cums_mutual");
        }],
        'M012_NT_VALIDADORES' => ['Fase 4: validadores de la Maestra Tarifaria por COD AUF + COD GEN SIESA (NT08 y NT17), precio regulado en contrato y precio final (NT03), mismo precio por COD MUTUAL FINAL como error (NT09)', function (PDO $pdo) {
            $u = $pdo->prepare('UPDATE proc_regla SET descripcion = ?, severidad = ?, accion_sugerida = ? WHERE codigo = ?');
            $u->execute(['Maestra Tarifaria: el precio del contrato o el precio final (+ IVA) supera el precio regulado', 'ERROR', 'Ajustar al precio regulado', 'NT03']);
            $u->execute(['Maestra Tarifaria: el COD MUTUAL no es único por COD AUF + COD GEN SIESA (validador 1)', 'ALERTA', 'Asignar un COD MUTUAL FINAL único', 'NT08']);
            $u->execute(['Maestra Tarifaria: el mismo COD MUTUAL FINAL tiene precios distintos', 'ERROR', 'Unificar el precio', 'NT09']);
        }],
        'M013_NT_UN_VALIDADOR' => ['Fase 4: un solo validador en la Maestra Tarifaria (NT17); se retiran NT08 y NT09; el precio regulado se compara con el precio correcto M (NT03)', function (PDO $pdo) {
            $pdo->exec("DELETE FROM proc_regla WHERE codigo IN ('NT08', 'NT09')");
            $pdo->prepare('UPDATE proc_regla SET descripcion = ? WHERE codigo = ?')->execute(['Maestra Tarifaria: el precio correcto M supera el precio regulado', 'NT03']);
            $pdo->prepare('UPDATE proc_regla SET descripcion = ? WHERE codigo = ?')->execute(['Maestra Tarifaria: el COD MUTUAL FINAL no es único por COD AUF + COD GEN SIESA (validador)', 'NT17']);
        }],
        'M014_NT_TIPO_SERVICIO' => ['Fase 4: el ID MUTUAL debe ser único por tipo de servicio y coincidir con la Maestra Tarifaria (NT18); la glosa de tarifa indica cómo corregirla (NT02)', function (PDO $pdo) {
            $pdo->prepare('UPDATE proc_regla SET descripcion = ?, accion_sugerida = ? WHERE codigo = ?')
                ->execute(['Maestra Tarifaria: el precio M supera el precio del contrato (glosa de tarifa)', 'Bajar el precio de venta de las filas indicadas al del contrato', 'NT02']);
        }],
        'M015_NT_TIPO_SERVICIO_2' => ['Fase 4: un ID MUTUAL en los dos servicios del contrato no es error: se toma el precio del tipo de la Maestra Tarifaria; NT18 solo si el tipo no coincide', function (PDO $pdo) {
            $pdo->prepare('UPDATE proc_regla SET descripcion = ?, accion_sugerida = ? WHERE codigo = ?')
                ->execute(['Maestra Tarifaria: el tipo de servicio del COD MUTUAL en el contrato no coincide con el de la Maestra Tarifaria', 'Corregir el tipo de producto en la Maestra Tarifaria', 'NT18']);
        }],
        'M016_NT_LINEA_POR_CONTRATO' => ['Fase 4: la Maestra Tarifaria tiene una línea por CUM a facturar y CONTRATO (CAPITA / EVENTO / EVENTO SAS); se crean las líneas que faltan con el precio de la base', function (PDO $pdo) {
            require_once __DIR__ . '/nota_tecnica.php';
            if (!columna_existe($pdo, 'nt_item', 'contrato')) {
                // Contrato de las líneas actuales: el que calculaba el aplicativo (CAPITA si el COD AUF está en el Listado Cápita).
                $cap = (int) _carga_vigente($pdo, 'LISTADO_CAPITA');
                $calc = "CASE WHEN UPPER(cod_auf) IN (SELECT UPPER(codigo_cup) FROM ref_capita WHERE carga_id = $cap) THEN 'CAPITA' ELSE 'EVENTO' END";
                if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                    $pdo->exec("ALTER TABLE nt_item ADD COLUMN contrato VARCHAR(12) NOT NULL DEFAULT 'EVENTO' AFTER cum_facturar");
                    $pdo->exec("UPDATE nt_item SET contrato = $calc");
                    $pdo->exec('ALTER TABLE nt_item DROP INDEX cum_facturar, ADD UNIQUE KEY uq_nt_cum_contrato (cum_facturar, contrato)');
                } else {
                    // SQLite no cambia un UNIQUE: se recrea la tabla conservando los id (nt_producto / nt_precio_calculo los usan).
                    $cols = implode(', ', array_map(fn($c) => $c['name'], $pdo->query('PRAGMA table_info(nt_item)')->fetchAll()));
                    $def = str_replace('{PK}', 'INTEGER PRIMARY KEY AUTOINCREMENT', esquema_tablas()['nt_item']);
                    $pdo->exec("CREATE TABLE nt_item_nuevo ($def)");
                    $pdo->exec("INSERT INTO nt_item_nuevo ($cols, contrato) SELECT $cols, $calc FROM nt_item");
                    $pdo->exec('DROP TABLE nt_item');
                    $pdo->exec('ALTER TABLE nt_item_nuevo RENAME TO nt_item');
                    foreach (esquema_indices() as [$nombre, $tabla, $c]) if ($tabla === 'nt_item') $pdo->exec("CREATE INDEX IF NOT EXISTS $nombre ON nt_item ($c)");
                }
            }
            nt_repetir_lineas($pdo, null);
        }],
        'M017_NT_PRECIO_UNO' => ['Fase 4: excepción de precio 1 aprobada por el Administrador (NT04)', function (PDO $pdo) {
            $pdo->prepare('UPDATE proc_regla SET descripcion = ?, accion_sugerida = ? WHERE codigo = ?')
                ->execute(['Maestra Tarifaria: precio vacío o igual a 1 (el precio 1 lo puede aprobar el Administrador)', 'Digitar el precio o aprobar la excepción de precio 1', 'NT04']);
        }],
        'M018_NT_CUM_INACTIVO' => ['Fase 4: NT10/NT11 replanteadas; NT24 = CUM inactivo con registro vencido y activo en SIESA o MEDICAR', function (PDO $pdo) {
            $u = $pdo->prepare('UPDATE proc_regla SET descripcion = ?, accion_sugerida = ? WHERE codigo = ?');
            $u->execute(['Maestra Tarifaria: CUM no encontrado en INVIMA', 'Verificar el CUM', 'NT10']);
            $u->execute(['Maestra Tarifaria: CUM activo con registro sanitario no vigente', 'Verificar el registro', 'NT11']);
        }],
        'M019_NT11_ERROR_NT10_MANUAL' => ['Fase 4: NT11 pasa a error (registro no vigente, no se puede dispensar ni comprar); NT10 se valida a mano', function (PDO $pdo) {
            $u = $pdo->prepare('UPDATE proc_regla SET descripcion = ?, severidad = ?, base_comparada = ?, accion_sugerida = ? WHERE codigo = ?');
            $u->execute(['Maestra Tarifaria: CUM no encontrado en INVIMA (se valida a mano)', 'ALERTA', 'INVIMA', 'Validar el CUM a mano con el botón «Validar CUM»', 'NT10']);
            $u->execute(['Maestra Tarifaria: CUM activo con registro no vigente y activo en SIESA o MEDICAR', 'ERROR', 'INVIMA / SIESA / MEDICAR',
                'No se puede dispensar ni comprar: inactivar el código en SIESA y/o MEDICAR', 'NT11']);
        }],
        'M020_NT_PRECIO_ORIGINAL_SI_SUPERA_REGULADO' => ['Fase 4: las líneas creadas con el precio del contrato que superan el regulado toman el PRECIO DE VENTA de la línea original', function (PDO $pdo) {
            require_once __DIR__ . '/nota_tecnica.php';
            $st = $pdo->prepare('SELECT UPPER(cod_gen), precio_regulado FROM ref_precio_regulado WHERE carga_id = ?');
            $st->execute([(int) _carga_vigente($pdo, 'PRECIOS_REGULADOS')]);
            $reg = $st->fetchAll(PDO::FETCH_KEY_PAIR);
            // línea original de cada CUM: la que no fue creada por el aplicativo (la más antigua)
            $orig = [];
            foreach ($pdo->query("SELECT * FROM nt_item WHERE origen_precio NOT IN ('CONTRATO', 'LISTADO CAPITA', 'COPIA') OR origen_precio IS NULL ORDER BY id") as $r) $orig[$r['cum_facturar']] ??= $r;
            $upd = $pdo->prepare("UPDATE nt_item SET precio_venta = ?, origen_precio = 'COPIA', actualizado_en = ? WHERE id = ?");
            $cambios = [];
            foreach ($pdo->query("SELECT * FROM nt_item WHERE origen_precio = 'CONTRATO' AND contrato IN ('EVENTO', 'EVENTO SAS')")->fetchAll() as $r) {
                $g = $reg[_u($r['cod_auf'])] ?? null; $o = $orig[$r['cum_facturar']] ?? null;
                if ($g === null || !$o || $o['precio_venta'] === null || $r['precio_venta'] === null) continue;
                if (round((float) $r['precio_venta'] * (1 + (float) $r['iva'])) <= round((float) $g)) continue;
                $upd->execute([$o['precio_venta'], ahora(), $r['id']]);
                $cambios[] = ['id' => $r['id'], 'cum_facturar' => $r['cum_facturar'], 'contrato' => $r['contrato'], 'antes' => (float) $r['precio_venta'], 'despues' => (float) $o['precio_venta'], 'desde' => $o['contrato']];
            }
            if ($cambios) auditar(null, 'NT_PRECIO_LINEA_ORIGINAL', 'nt_item', null, null, ['lineas' => count($cambios), 'detalle' => $cambios]);
        }],
        'M021_FASE5_REGLAS' => ['Fase 5: reglas del motor ajustadas con el usuario (E02, A01, A02 y A06 retiradas; A03 pasa a error; E03, E04, E05, E10 con aprobación del Administrador)', function (PDO $pdo) {
            $pdo->exec("UPDATE proc_regla SET activa = 0 WHERE codigo IN ('E02', 'A01', 'A02', 'A06', 'E11')");
            $u = $pdo->prepare('UPDATE proc_regla SET descripcion = ?, severidad = ?, accion_sugerida = ? WHERE codigo = ?');
            $u->execute(['La molécula ya existe en Medicar: el producto no se crea', 'ERROR', 'No se crea', 'A03']);
            $u->execute(['El COD AUF no existe en COD GEN', 'ERROR', 'Diligenciar la molécula: la aprueba el Administrador', 'E03']);
            $u->execute(['El laboratorio no existe en el catálogo', 'ERROR', 'Lo aprueba el Administrador', 'E04']);
            $u->execute(['La presentación no existe en el catálogo', 'ERROR', 'Diligenciar la unidad de orden: la aprueba el Administrador', 'E05']);
            $u->execute(['Registro INVIMA no vigente (igual que la Maestra Tarifaria)', 'ERROR', 'No se crea', 'E09']);
            $u->execute(['El CUM no está en la Maestra Tarifaria o su línea no está en el contrato', 'ERROR', 'Incluir en la Maestra Tarifaria (Administrador) / PTE POR INCLUIR EN EL CONTRATO', 'E10']);
            $u->execute(['La línea de la Maestra Tarifaria tiene errores', 'ERROR', 'Corregir primero la Maestra Tarifaria', 'E12']);
            $u->execute(['Subplán sin ID o inactivo', 'ERROR', 'Revisar el catálogo de subplanes', 'E15']);
            $u->execute(['El CUM existe inactivo en SIESA (se crea si el registro está vigente)', 'ALERTA', 'Confirmar', 'A04']);
        }],
        'M025_LABORATORIOS_DEPURAR' => ['Fase 10: laboratorios repetidos (solo cambian puntos o espacios): queda el código más usado en el Maestro SIESA; los demás se inactivan', function (PDO $pdo) {
            require_once __DIR__ . '/catalogos_admin.php';
            // OPHALAC: empate de uso en SIESA (3 y 3); queda el 588, bien escrito, y se inactiva el 392 («OPHALACS.A.»)
            $st = $pdo->prepare("SELECT id FROM cat_laboratorio WHERE codigo = '392' AND nombre = 'LABORATORIOS FARMACEUTICOS OPHALACS.A.' AND activo = 1");
            $st->execute();
            if ($id392 = $st->fetchColumn()) {
                $pdo->prepare('UPDATE cat_laboratorio SET activo = 0, actualizado_en = ? WHERE id = ?')->execute([ahora(), $id392]);
                auditar(null, 'LABORATORIO_DEPURAR', 'cat_laboratorio', $id392, ['activo' => 1, 'codigo' => '392'], ['activo' => 0, 'queda' => '588 LABORATORIOS FARMACEUTICOS OPHALAC S.A.', 'motivo' => 'empate de uso; queda el nombre bien escrito']);
            }
            laboratorio_depurar($pdo, null);
        }],
        'M026_NT17_RETIRADA' => ['7H: se retira el COD MUTUAL FINAL de las validaciones; la regla NT17 queda inactiva (decisión del usuario, 2026-10-11)', function (PDO $pdo) {
            $pdo->prepare("UPDATE proc_regla SET activa = 0 WHERE codigo = 'NT17'")->execute();
        }],
        'M027_A04_A_E18' => ['El CUM inactivo en SIESA pasa de alerta (A04) a error (E18): activar el PLU en SIESA (decisión del usuario, 2026-10-13)', function (PDO $pdo) {
            $pdo->prepare("UPDATE proc_regla SET activa = 0 WHERE codigo = 'A04'")->execute();
        }],
        'M028_MAESTRA_TARIFARIA' => ['La Nota Técnica pasa a llamarse Maestra Tarifaria (decisión del usuario, 2026-10-14): textos guardados en la base', function (PDO $pdo) {
            $cols = ['proc_regla' => ['descripcion', 'accion_sugerida', 'base_comparada'], 'seg_permiso' => ['nombre', 'descripcion'], 'seg_modulo' => ['nombre', 'descripcion'],
                     'ref_base' => ['nombre', 'descripcion'], 'sis_parametro' => ['descripcion']];
            foreach ($cols as $t => $cs) foreach ($cs as $c) {
                try {
                    foreach (['Nota Técnica' => 'Maestra Tarifaria', 'Nota técnica' => 'Maestra tarifaria', 'nota técnica' => 'maestra tarifaria', 'NOTA TECNICA' => 'MAESTRA TARIFARIA'] as $a => $b)
                        $pdo->prepare("UPDATE $t SET $c = REPLACE($c, ?, ?) WHERE $c LIKE ?")->execute([$a, $b, "%$a%"]);
                } catch (Throwable $e) { /* la tabla o la columna no existe en esta instalación */ }
            }
        }],
        'M029_MAESTRA_TARIFARIA_PARAMETROS' => ['Maestra Tarifaria: descripción de los parámetros (tabla cat_parametro)', function (PDO $pdo) {
            foreach (['Nota Técnica' => 'Maestra Tarifaria', 'Nota técnica' => 'Maestra tarifaria', 'nota técnica' => 'maestra tarifaria'] as $a => $b)
                $pdo->prepare('UPDATE cat_parametro SET descripcion = REPLACE(descripcion, ?, ?) WHERE descripcion LIKE ?')->execute([$a, $b, "%$a%"]);
        }],
        'M030_MAESTRA_TARIFARIA_ABREVIATURA' => ['Maestra Tarifaria: «la NT» en los textos de las reglas y parámetros', function (PDO $pdo) {
            foreach (['proc_regla' => ['codigo', ['descripcion', 'accion_sugerida']], 'cat_parametro' => ['clave', ['descripcion']]] as $t => [$llave, $cols]) {
                foreach ($pdo->query("SELECT $llave, " . implode(', ', $cols) . " FROM $t")->fetchAll() as $r) {
                    foreach ($cols as $c) {
                        $n = preg_replace('/\bla NT\b(?![0-9A-Za-z_])/u', 'la Maestra Tarifaria', (string) $r[$c]);
                        if ($r[$c] !== null && $n !== $r[$c]) $pdo->prepare("UPDATE $t SET $c = ? WHERE $llave = ?")->execute([$n, $r[$llave]]);
                    }
                }
            }
        }],
        'M031_ELIMINAR_A04_NT17' => ['Se eliminan las reglas A04 (reemplazada por E18) y NT17 (COD MUTUAL FINAL eliminado): decisión del usuario, 2026-10-14', function (PDO $pdo) {
            $pdo->exec("DELETE FROM proc_resultado WHERE regla_codigo IN ('A04', 'NT17')");
            $pdo->exec("DELETE FROM proc_regla WHERE codigo IN ('A04', 'NT17')");
        }],
        'M037_NT29_MAESTRA_TARIFARIA' => ['NT29 se valida en la Maestra Tarifaria (la MT manda; SIESA y MEDICAR son espejo): decisión del usuario, 2026-11-05', function (PDO $pdo) {
            $pdo->prepare('UPDATE proc_regla SET descripcion = ?, base_comparada = ?, accion_sugerida = ? WHERE codigo = ?')
                ->execute(['Maestra Tarifaria: la línea tiene más de un PLU activo en la Maestra Tarifaria (no aplica a insumos 799 ni a VITAL NO DISPONIBLE)', 'Maestra Tarifaria',
                    'Inactivar en la Maestra Tarifaria el PLU que sobra (Inconsistencias pide luego inactivarlo en SIESA y MEDICAR) o, si el CUM sí lleva varios PLU, «Aprobar varios PLU»', 'NT29']);
        }],
        'M036_RETIRAR_NT' => ['7J: NT27 = inactiva en SIESA y en MEDICAR (se retira); activa en SIESA e inactiva en MEDICAR pasa a corrección en MEDICAR', function (PDO $pdo) {
            $pdo->prepare('UPDATE proc_regla SET descripcion = ?, base_comparada = ?, accion_sugerida = ? WHERE codigo = ?')
                ->execute(['Maestra Tarifaria: la línea está inactiva en SIESA y en MEDICAR', 'Maestro SIESA / Maestra MEDICAR', 'Retirar la línea de la Maestra Tarifaria (botón «Retirar»; queda en la versión anterior)', 'NT27']);
        }],
        'M035_VER_FUERA_CONTRATO' => ['Los perfiles de consulta ven la Maestra Tarifaria sin las líneas que no están en el contrato; Administrador y Creación las ven (decisión del usuario, 2026-10-19)', function (PDO $pdo) {
            $perm = (int) $pdo->query("SELECT id FROM seg_permiso WHERE codigo = 'NT.VER_FUERA_CONTRATO'")->fetchColumn();
            foreach ($pdo->query("SELECT id FROM seg_perfil WHERE codigo IN ('ADMIN', 'CREACION')")->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                insertar_si_no_existe($pdo, 'seg_perfil_permiso', ['perfil_id' => (int) $pid, 'permiso_id' => $perm], ['perfil_id', 'permiso_id']);
            }
        }],
        'M034_NOMBRE_INCONSISTENCIAS' => ['El módulo de correcciones se llama «Inconsistencias» (decisión del usuario, 2026-10-19)', function (PDO $pdo) {
            $pdo->exec("UPDATE proc_regla SET accion_sugerida = REPLACE(accion_sugerida, 'ver Correcciones SIESA / MEDICAR', 'ver Inconsistencias') WHERE codigo = 'NT29'");
        }],
        'M033_CORRECCIONES' => ['Módulo de correcciones SIESA / MEDICAR: consultar (Administrador, Creación y Consulta) y gestionar (Administrador)', function (PDO $pdo) {
            $perm = fn($c) => (int) $pdo->query('SELECT id FROM seg_permiso WHERE codigo = ' . $pdo->quote($c))->fetchColumn();
            foreach ([['CORRECCION.CONSULTAR', ['ADMIN', 'CREACION', 'CONSULTA']], ['CORRECCION.GESTIONAR', ['ADMIN']]] as [$p, $perfiles]) {
                $lista = implode(', ', array_map([$pdo, 'quote'], $perfiles));
                foreach ($pdo->query("SELECT id FROM seg_perfil WHERE codigo IN ($lista)")->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                    insertar_si_no_existe($pdo, 'seg_perfil_permiso', ['perfil_id' => (int) $pid, 'permiso_id' => $perm($p)], ['perfil_id', 'permiso_id']);
                }
            }
        }],
        'M032_PRECIO_VER' => ['Permiso PRECIO.VER (ver precios y tarifas) para Administrador, Creación y Consulta; el perfil «Consulta sin precios» no lo tiene', function (PDO $pdo) {
            $perm = (int) $pdo->query("SELECT id FROM seg_permiso WHERE codigo = 'PRECIO.VER'")->fetchColumn();
            foreach ($pdo->query("SELECT id FROM seg_perfil WHERE codigo IN ('ADMIN', 'CREACION', 'CONSULTA')")->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                insertar_si_no_existe($pdo, 'seg_perfil_permiso', ['perfil_id' => (int) $pid, 'permiso_id' => $perm], ['perfil_id', 'permiso_id']);
            }
        }],
        'M024_SUBPLANES_CLASIFICACION' => ['Fase 7D: clasificación CAPITA / EVENTO y fuente de precio de los subplanes (especificación §3 y §4)', function (PDO $pdo) {
            $u = $pdo->prepare('UPDATE cat_subplan SET clasificacion = COALESCE(clasificacion, ?), fuente = COALESCE(fuente, ?) WHERE id_subplan = ?');
            foreach ([1 => ['CAPITA', 'LISTADO_CAPITA'], 2 => ['EVENTO', 'LISTADO_EVENTO_SAS'], 3 => ['EVENTO', 'CONTRATO_EVENTO'],
                      4 => ['EVENTO', 'CONTRATO_EVENTO'], 6 => ['CAPITA', 'LISTADO_CONSORCIO'], 9 => ['EVENTO', 'CONTRATO_EVENTO']] as $id => [$c, $f]) $u->execute([$c, $f, $id]);
        }],
        'M023_CONTRATO_EVENTO_SIN_NUMERO' => ['El Contrato Evento se llama sin número (el número sale del archivo cargado)', function (PDO $pdo) {
            $pdo->prepare("UPDATE ref_base SET nombre = 'Contrato Evento', descripcion = ? WHERE codigo = 'CONTRATO_EVENTO_22382'")
                ->execute(['Contrato de evento con Mutual Ser. El número del contrato se toma del archivo cargado.']);
            $pdo->exec("UPDATE proc_regla SET base_comparada = 'Contrato Evento' WHERE base_comparada = 'Contrato Evento 22382'");
        }],
        'M022_A03_NO_ES_ERROR' => ['Fase 5: A03 se retira; si la molécula ya existe en Medicar el producto se crea y la molécula no va en el formato 1', function (PDO $pdo) {
            $pdo->prepare('UPDATE proc_regla SET activa = 0, severidad = ?, descripcion = ?, accion_sugerida = ? WHERE codigo = ?')
                ->execute(['ALERTA', 'Molécula ya existe en Medicar (no se incluye en el formato 1)', 'Ninguna: se omite automáticamente', 'A03']);
        }],
        'M004_QUITAR_CATEGORIA_PBS' => ['Se retira la lista CATEGORIA PBS: dato duplicado, se toma de COD GEN (POS / NO POS)', function (PDO $pdo) {
            $antes = $pdo->query("SELECT valor FROM cat_lista_valor WHERE lista = 'CATEGORIA_PBS'")->fetchAll(PDO::FETCH_COLUMN);
            $pdo->exec("DELETE FROM cat_lista_valor WHERE lista = 'CATEGORIA_PBS'");
            $pdo->exec("DELETE FROM cat_lista WHERE codigo = 'CATEGORIA_PBS'");
            auditar(null, 'LISTA_RETIRAR', 'cat_lista', 'CATEGORIA_PBS', $antes, null);
        }],
    ];
    $aplicadas = [];
    foreach ($migraciones as $codigo => [$desc, $fn]) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM sis_migracion WHERE codigo = ?');
        $st->execute([$codigo]);
        $aplicada = (int) $st->fetchColumn() > 0;
        $st->closeCursor(); $st = null; // SQLite no permite DROP TABLE con consultas abiertas
        if ($aplicada) continue;
        $pdo->beginTransaction();
        $fn($pdo);
        insertar($pdo, 'sis_migracion', ['codigo' => $codigo, 'descripcion' => $desc, 'aplicada_en' => ahora()]);
        auditar(null, 'MIGRACION_DATOS', 'sis_migracion', $codigo, null, $desc);
        // En MySQL un DROP/ALTER confirma la transacción de forma implícita: solo se confirma si sigue abierta.
        if ($pdo->inTransaction()) $pdo->commit();
        $aplicadas[] = "$codigo: $desc";
    }
    return $aplicadas;
}

/** Registra una decisión para cada hallazgo de la última carga de catálogos que cumpla el patrón. */
function registrar_decisiones_carga(PDO $pdo, string $tabla, string $patron, string $decision, ?int $usuarioId = null): int {
    $detalle = $pdo->query("SELECT detalle FROM ref_carga WHERE tipo_base = 'CATALOGOS_EXCEL' AND vigente = 1 ORDER BY id DESC LIMIT 1")->fetchColumn();
    $n = 0;
    foreach ((json_decode((string) $detalle, true)[$tabla]['omitidas'] ?? []) as $h) {
        if (!preg_match($patron, $h)) continue;
        $catalogo = str_replace('cat_', '', $tabla);
        $st = $pdo->prepare('SELECT COUNT(*) FROM cat_decision WHERE catalogo = ? AND hallazgo = ?');
        $st->execute([$catalogo, $h]);
        if ((int) $st->fetchColumn() > 0) continue;
        insertar($pdo, 'cat_decision', ['catalogo' => $catalogo, 'hallazgo' => $h, 'decision' => $decision, 'decidido_por' => $usuarioId, 'decidido_en' => ahora()]);
        $n++;
    }
    return $n;
}

/** Crea el administrador inicial si no hay ninguno. Devuelve la clave temporal generada (o null). */
function semilla_admin(PDO $pdo): ?string {
    $hay = (int) $pdo->query("SELECT COUNT(*) FROM seg_usuario_perfil up JOIN seg_perfil p ON p.id = up.perfil_id WHERE p.codigo = 'ADMIN'")->fetchColumn();
    if ($hay > 0) return null;
    $clave = generar_clave_temporal();
    $uid = insertar($pdo, 'seg_usuario', [
        'usuario' => 'admin', 'nombre' => 'Administrador del aplicativo', 'correo' => null,
        'password_hash' => password_hash($clave, PASSWORD_DEFAULT),
        'activo' => 1, 'debe_cambiar_clave' => 1, 'intentos_fallidos' => 0, 'creado_en' => ahora(),
    ]);
    $pid = (int) $pdo->query("SELECT id FROM seg_perfil WHERE codigo = 'ADMIN'")->fetchColumn();
    insertar($pdo, 'seg_usuario_perfil', ['usuario_id' => $uid, 'perfil_id' => $pid]);
    return $clave;
}

function generar_clave_temporal(): string {
    $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $s = '';
    for ($i = 0; $i < 14; $i++) $s .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
    return $s . random_int(10, 99) . '*';
}

function semilla_parametros(PDO $pdo): void {
    $p = [
        ['SIESA_LISTA_PRECIOS',     'L01',        'Código de lista de precios en ITEM_PRECIOS'],
        ['LOTE_CODIGO',             'PH001',      'Lote por defecto (obligatorio en SIESA)'],
        ['LOTE_FECHA_CREACION',     '20230101',   'Fecha de creación del lote por defecto (AAAAMMDD)'],
        ['LOTE_FECHA_VENCIMIENTO',  '20301201',   'Fecha de vencimiento del lote por defecto (AAAAMMDD)'],
        ['SIESA_DESC_MAX',          '40',         'Largo máximo de f120_descripcion'],
        ['SIESA_DESC_CORTA_MAX',    '20',         'Largo máximo de f120_descripcion_corta'],
        ['SIESA_DATO_TECNICO_MAX',  '60',         'Largo máximo de f123_dato (descripciones técnicas)'],
        ['MEDICAR_CONVENIO',        'Mutual Ser', 'CONVENIO en el formato 8 de Medicar'],
        ['MEDICAR_PLAN',            'CONTRIBUTIVO','PLAN en el formato 8 de Medicar'],
        ['VALORES_EXCEPCION_CUM',   'NO APLICA|VITAL NO DISPONIBLE|PREPARADO MAGISTRAL', 'Valores de CUM / registro que se tratan como excepción (separados por |)'],
        ['INVIMA_PRIORIDAD_ESTADOS','VIGENTE|EN TRAMITE|OTROS|VENCIDO', 'Prioridad cuando un CUM aparece en varias bases INVIMA'],
        ['INVIMA_DIAS_ALERTA_VENC', '90',         'Días antes del vencimiento del registro para generar ALERTA'],
        ['INVIMA_API_BASE',         'https://www.datos.gov.co/resource/', 'URL base de datos abiertos (Socrata)'],
        ['BASE_TAMANO_MAX_MB',      '200',        'Tamaño máximo de archivo en el cargue manual de bases (MB)'],
        ['MUTUAL_MAESTRA_URL',      '',           'Enlace (SharePoint) del Excel «Consulta de medicamentos» de Mutual Ser. Solo el Administrador lo ve y lo cambia'],
        ['NT_ANIO_PRECIO',          '2026',       'Año del precio de venta de la Maestra Tarifaria (encabezado PRECIO DE VENTA del archivo exportado)'],
        ['NT_PORCENTAJE_PRECIO',    '20',         'Calculadora de precio de la Maestra Tarifaria: % que se suma al precio de compra (tope: precio regulado)'],
        ['INVIMA_DATASETS',         'VIGENTE:i7cb-raxc|VENCIDO:vwwf-4ftk|EN TRAMITE:vgr4-gemg|OTROS:spzp-dfuc|RSNSO:ui32-p9f2', 'Datasets INVIMA (fuente:id)'],
    ];
    foreach ($p as [$k, $v, $d]) {
        insertar_si_no_existe($pdo, 'cat_parametro', ['clave' => $k, 'valor' => $v, 'descripcion' => $d, 'actualizado_en' => ahora()], ['clave']);
    }
}

/** Listas desplegables del FORMATO del Excel (hoja LISTAS), como catálogos independientes. */
function semilla_listas(PDO $pdo): void {
    foreach ([
        ['TIPO_MEDICAMENTO', 'Tipo de medicamento',     'Columna TIPO DE MEDICAMENTO del formato. Define el tipo de inventario SIESA.'],
        ['ESTADO_INVIMA',    'Estado INVIMA',           'Columna ESTADO INVIMA del formato.'],
        ['ENVASE',           'Envase de presentación',  'Columna PRESENTACION 1 del formato. Forma la presentación agrupada (ENVASE X CANTIDAD).'],
        // CATEGORIA PBS no es lista: es dato duplicado, ya lo trae COD GEN (POS / NO POS). Decisión del usuario 2026-09-25.
    ] as $i => [$c, $n, $d]) {
        insertar_si_no_existe($pdo, 'cat_lista', ['codigo' => $c, 'nombre' => $n, 'descripcion' => $d, 'es_sistema' => 1,
            'activo' => 1, 'orden' => $i + 1, 'creado_en' => ahora()], ['codigo']);
    }
}

function semilla_catalogos_fijos(PDO $pdo): void {
    // Confirmado por el usuario (R1): grupo impositivo e inventario son columnas independientes.
    foreach ([
        ['0016', 'SIN IVA', 'COMPRAS 2.5% ICA MEDICAMENTOS 0.7%', 0],
        ['0027', 'CON IVA', 'IVA 19% COMP. MEDICAMENTOS 2,5% ICA 0,7%', 19],
    ] as [$c, $e, $d, $iva]) {
        insertar_si_no_existe($pdo, 'cat_grupo_impositivo', ['codigo' => $c, 'etiqueta_iva' => $e, 'descripcion' => $d, 'iva_medicar' => $iva], ['codigo']);
    }
    foreach ([
        ['IN350501', 'MEDICAMENTOS', 'MEDICAMENTOS'],
        ['IN350502', 'INSUMOS',      'INSUMOS Y DISPOSITIVOS MEDICOS'],
    ] as [$c, $t, $d]) {
        insertar_si_no_existe($pdo, 'cat_tipo_inventario', ['codigo' => $c, 'tipo_medicamento' => $t, 'descripcion' => $d], ['codigo']);
    }
    // Subplanes Medicar confirmados (R9): 1-7 y 9. Administrables por el perfil Administrador.
    foreach ([
        [1, 'CAPITA PBS'], [2, 'EVENTO SAS SIN AUTORIZACION'], [3, 'EVENTO POS'], [4, 'EVENTO NO PBS'],
        [5, 'CAPITA PBS CONDICIONADO'], [6, 'CONSORCIO'], [7, 'CAPITA AUDIFARMA'],
        [9, 'TRANSFERENCIA DE PENDIENTES CON AUTORIZACION'],
    ] as [$id, $n]) {
        insertar_si_no_existe($pdo, 'cat_subplan', ['id_subplan' => $id, 'nombre' => $n, 'activo' => 1, 'actualizado_en' => ahora()], ['id_subplan']);
    }
}

/**
 * Bases externas que hacen posibles las validaciones (definidas por el usuario el 2026-09-25).
 * MANUAL: el usuario carga el archivo. AUTOMATICA: el aplicativo la actualiza (fase indicada).
 * guarda_versiones = 1 conserva todos los archivos cargados; 0 reemplaza el anterior.
 * Se actualiza la definición en cada instalación (no son datos que edite el usuario todavía).
 */
function semilla_bases(PDO $pdo): void {
    $bases = [
        ['MAESTRO_SIESA',        'Maestro SIESA',           'Ítems ya creados en SIESA: evita duplicados de PLU y CUM activos.', 'MANUAL',     1, null],
        ['INVIMA',               'INVIMA',                  'Estado de registros sanitarios y CUM (datos abiertos datos.gov.co).', 'AUTOMATICA', 0, 3],
        ['LISTADO_CAPITA',       'Listado Cápita',          'Contrato CÁPITA / EVENTO por código genérico.',                      'MANUAL',     1, null],
        ['LISTADO_EVENTO_SAS',   'Listado EVENTO SAS',      'Listado de productos EVENTO SAS.',                                    'MANUAL',     1, null],
        ['CONTRATO_EVENTO_22382','Contrato Evento',         'Contrato de evento con Mutual Ser. El número del contrato se toma del archivo cargado.', 'MANUAL', 1, null],
        ['MAESTRA_MEDICAR',      'Maestra MEDICAR',         'Moléculas y artículos ya creados en Medicar (evita duplicar moléculas).', 'MANUAL', 1, null], // guardan versiones (2026-10-19)
        // Fase 7E: queda abierta para cargar el listado; la lectura y la validación se arman cuando llegue el archivo
        ['LISTADO_CONSORCIO',    'Listado Consorcio',       'Listado del subplán CONSORCIO (clasificación CAPITA). Se carga aquí; la lectura de sus columnas se define cuando se tenga el archivo.', 'MANUAL', 1, null],
        ['PRECIOS_REGULADOS',    'Precios regulados',       'Precio unitario máximo regulado por código genérico (circular de precios).', 'MANUAL', 1, null],
        // Se descarga sola del enlace de Mutual Ser (parámetro MUTUAL_MAESTRA_URL), como el script MaestraMutualser.R.
        ['MEDICAMENTOS_MUTUAL',  'Maestra Mutual Ser',      'Consulta de medicamentos de Mutual Ser: qué CUM está asociado a cada ID MUTUAL (valida «CUM y código» de la Maestra Tarifaria).', 'AUTOMATICA', 0, 4, 'xlsx'],
    ];
    foreach ($bases as $i => [$c, $n, $d, $modo, $ver, $fase]) {
        upsert($pdo, 'ref_base', [
            'codigo' => $c, 'nombre' => $n, 'descripcion' => $d, 'modo' => $modo, 'guarda_versiones' => $ver,
            'fase_automatica' => $fase, 'extensiones' => $bases[$i][6] ?? 'xlsx,xlsm,csv', 'orden' => $i + 1, 'activo' => 1,
        ], ['codigo']);
    }
}

/** Catálogo de reglas de validación (el motor se implementa en la siguiente fase). */
function semilla_reglas(PDO $pdo): void {
    $r = [
        // ERROR: bloquea la creación
        ['E01', 'Campo obligatorio vacío',                                        'ERROR',  'Formulario',          'Diligenciar el campo'],
        ['E02', 'Valor fuera de la lista permitida',                              'ERROR',  'Catálogos',           'Seleccionar un valor de la lista'],
        ['E03', 'Código genérico Audifarma no existe en COD GEN',                 'ERROR',  'COD GEN',             'Verificar el código o crearlo en COD GEN'],
        ['E04', 'Laboratorio no existe en el catálogo',                           'ERROR',  'Laboratorios',        'Crear el laboratorio (Administrador) o corregir el nombre'],
        ['E05', 'Presentación / unidad de orden no existe en el catálogo',        'ERROR',  'Presentaciones',      'Crear la presentación (Administrador) o corregirla'],
        ['E06', 'El producto ya se encuentra creado (PLU existe en Maestro SIESA)','ERROR', 'Maestro SIESA',       'No es posible realizar nuevamente la creación'],
        ['E07', 'El producto ya se encuentra creado en el aplicativo',            'ERROR',  'Productos creados',   'No es posible realizar nuevamente la creación'],
        ['E08', 'El CUM ya está ACTIVO en Maestro SIESA',                         'ERROR',  'Maestro SIESA',       'Revisar si corresponde a una actualización'],
        ['E09', 'Registro sanitario / CUM no vigente en INVIMA',                  'ERROR',  'INVIMA',              'Verificar el registro en INVIMA'],
        ['E10', 'ID MUTUAL no encontrado en la Maestra Tarifaria para el CUM',         'ERROR',  'Maestra Tarifaria',        'Solicitar la inclusión en la Maestra Tarifaria'],
        ['E11', 'El ítem no está en el contrato (Maestra Tarifaria)',                  'ERROR',  'Maestra Tarifaria',        'PTE POR INCLUIR EN EL CONTRATO'],
        ['E12', 'Más de una fila de Maestra Tarifaria para el mismo ID MUTUAL',        'ERROR',  'Maestra Tarifaria',        'Depurar la Maestra Tarifaria'],
        ['E13', 'Precio vacío o igual a 1 sin excepción aprobada',                'ERROR',  'Maestra Tarifaria',        'El Administrador debe aprobar la excepción con motivo'],
        ['E14', 'Código de producto repetido dentro del mismo lote',              'ERROR',  'Lote',                'Eliminar la fila duplicada'],
        ['E15', 'Subplán sin ID en el catálogo de subplanes',                     'ERROR',  'Subplanes',           'Crear el subplán (Administrador)'],
        ['E16', 'Código RIPS no existe o no está habilitado',                     'ERROR',  'Tablas RIPS',         'Seleccionar un código RIPS válido'],
        // ALERTA: requiere revisión / aprobación del usuario
        ['A01', 'La descripción supera el largo permitido y será recortada',      'ALERTA', 'Parámetros SIESA',    'Revisar el texto recortado'],
        ['A02', 'Registro INVIMA próximo a vencer',                               'ALERTA', 'INVIMA',              'Confirmar vigencia'],
        ['A03', 'Molécula ya existe en Medicar (no se incluye en formato 1)',     'ALERTA', 'Moléculas Medicar',   'Ninguna: se omite automáticamente'],
        // Maestra Tarifaria (fase 4): las calcula el motor sobre cada fila de la Maestra Tarifaria
        ['NT01', 'Maestra Tarifaria: el COD MUTUAL no está en el contrato',                     'ERROR',  'Contrato Evento', 'Incluir en el contrato o corregir el código'],
        ['NT02', 'Maestra Tarifaria: el precio M supera el precio del contrato (glosa de tarifa)', 'ERROR', 'Contrato Evento', 'Bajar el precio de venta de las filas indicadas al del contrato'],
        ['NT18', 'Maestra Tarifaria: el tipo de servicio del COD MUTUAL en el contrato no coincide con el de la Maestra Tarifaria', 'ALERTA', 'Contrato Evento', 'Corregir el tipo de producto en la Maestra Tarifaria'],
        ['NT19', 'Maestra Tarifaria: el precio M no es igual a la tarifa pactada del Listado Cápita', 'ERROR', 'Listado Cápita', 'Igualar el precio de venta a la tarifa de cápita'],
        ['NT20', 'Maestra Tarifaria: la tarifa de cápita supera el precio regulado',            'ERROR',  'Listado Cápita',        'Revisar la tarifa con el contrato'],
        ['NT21', 'Maestra Tarifaria: el ID MUTUAL del Listado EVENTO SAS no es el COD MUTUAL de la Maestra Tarifaria', 'ALERTA', 'Listado EVENTO SAS', 'Corregir el COD MUTUAL en la Maestra Tarifaria o en el listado'],
        ['NT23', 'Maestra Tarifaria: el ID MUTUAL del Listado Cápita no es el COD MUTUAL de la Maestra Tarifaria', 'ALERTA', 'Listado Cápita', 'Corregir el COD MUTUAL en la Maestra Tarifaria o en el listado'],
        ['E18', 'El CUM ya está creado en SIESA pero inactivo',                    'ERROR',  'Maestro SIESA',       'Activar el PLU en SIESA: el producto ya existe, no se crea de nuevo'],
        ['E17', 'COD GEN con POS / NO POS en «ASIGNAR»',                           'ERROR',  'COD GEN',             'Asignar POS / NO POS (lo aprueba el Administrador)'],
        ['A08', 'Fecha o estado INVIMA distinto al de INVIMA',                     'ALERTA', 'INVIMA',              'Se llenan solos desde INVIMA: revisar el cambio'],
        ['NT22', 'Maestra Tarifaria: la línea no corresponde a su contrato (el producto no está en el Listado Cápita / EVENTO SAS)', 'ERROR', 'Maestra Tarifaria', 'Corregir el CONTRATO de la línea o inactivarla'],
        ['NT03', 'Maestra Tarifaria: el precio correcto M supera el precio regulado',            'ERROR',  'Precios regulados',     'Ajustar al precio regulado'],
        ['NT04', 'Maestra Tarifaria: precio vacío o igual a 1 (el precio 1 lo puede aprobar el Administrador)', 'ERROR', 'Maestra Tarifaria', 'Digitar el precio o aprobar la excepción de precio 1'],
        ['NT05', 'Maestra Tarifaria: el CUM no está enlazado con ese COD MUTUAL',               'ERROR',  'Maestra Mutual Ser',    'Corregir el código o solicitar el enlace'],
        ['NT06', 'Maestra Tarifaria: sin COD MUTUAL',                                           'ERROR',  'Maestra Tarifaria',          'Asignar el código Mutual'],
        ['NT07', 'Maestra Tarifaria: el CUM no está enlazado con ningún código (agregar)',      'ALERTA', 'Maestra Mutual Ser',    'Solicitar el enlace del CUM'],
        ['NT10', 'Maestra Tarifaria: CUM no encontrado en INVIMA (se valida a mano)',           'ALERTA', 'INVIMA',                'Validar el CUM a mano con el botón «Validar CUM»'],
        ['NT11', 'Maestra Tarifaria: CUM activo con registro no vigente y activo en SIESA o MEDICAR', 'ERROR', 'INVIMA / SIESA / MEDICAR', 'No se puede dispensar ni comprar: inactivar el código en SIESA y/o MEDICAR'],
        ['NT24', 'Maestra Tarifaria: CUM inactivo con registro vencido y todavía activo en SIESA o MEDICAR', 'ERROR', 'INVIMA / SIESA / MEDICAR', 'Inactivar el código en SIESA y/o MEDICAR'],
        ['NT12', 'Maestra Tarifaria: IVA distinto del grupo impositivo de SIESA',               'ALERTA', 'Maestro SIESA',         'Corregir el IVA'],
        ['NT13', 'Maestra Tarifaria: COD AUF no existe en COD GEN',                             'ALERTA', 'COD GEN',               'Corregir o crear el COD GEN'],
        ['NT25', 'Maestra Tarifaria: el CUM (o código del insumo) no está en el Maestro SIESA', 'ERROR', 'Maestro SIESA', 'Crear el producto en SIESA'],
        ['NT26', 'Maestra Tarifaria: la línea no está creada en MEDICAR en el subplán de su contrato', 'ERROR', 'Maestra MEDICAR', 'Crear el producto en MEDICAR'],
        ['NT29', 'Maestra Tarifaria: la línea tiene más de un PLU activo en la Maestra Tarifaria (no aplica a insumos 799 ni a VITAL NO DISPONIBLE)', 'ERROR', 'Maestra Tarifaria', 'Inactivar en la Maestra Tarifaria el PLU que sobra (Inconsistencias pide luego inactivarlo en SIESA y MEDICAR) o, si el CUM sí lleva varios PLU, «Aprobar varios PLU»'],
        ['NT28', 'Maestra Tarifaria: el subplán de la línea no está parametrizado (sin clasificación CAPITA / EVENTO o inactivo)', 'ALERTA', 'Subplanes', 'Parametrizar el subplán en Catálogos → Subplanes Medicar'],
        ['NT27', 'Maestra Tarifaria: la línea está inactiva en SIESA y en MEDICAR', 'ERROR', 'Maestro SIESA / Maestra MEDICAR', 'Retirar la línea de la Maestra Tarifaria (botón «Retirar»; queda en la versión anterior)'],
        ['NT30', 'Maestra Tarifaria: sin PLU en SIESA ni en MEDICAR', 'ERROR', 'Maestro SIESA / Maestra MEDICAR', 'Retirar la línea de la Maestra Tarifaria (botón «Retirar»; queda en la versión anterior)'],
        ['NT14', 'Maestra Tarifaria: COD GEN SIESA distinto del Maestro SIESA',                 'ALERTA', 'Maestro SIESA',         'Corregir el COD GEN SIESA'],
        ['NT16', 'Maestra Tarifaria: el producto está inactivo en el contrato',                 'ALERTA', 'Contrato Evento', 'Confirmar con el contrato'],
        ['A05', 'Precio 1 con excepción aprobada',                                'ALERTA', 'Maestra Tarifaria',        'Verificar la excepción'],
        ['A06', 'ID MUTUAL distinto del código genérico Audifarma',               'ALERTA', 'Maestra Tarifaria',        'Confirmar la relación'],
        ['A07', 'Base de referencia desactualizada',                              'ALERTA', 'Bases de referencia', 'Actualizar la base antes de crear'],
    ];
    foreach ($r as $i => [$c, $d, $s, $b, $a]) {
        insertar_si_no_existe($pdo, 'proc_regla', [
            'codigo' => $c, 'descripcion' => $d, 'severidad' => $s, 'base_comparada' => $b,
            'accion_sugerida' => $a, 'activa' => 1, 'orden' => $i + 1,
        ], ['codigo']);
    }
}
