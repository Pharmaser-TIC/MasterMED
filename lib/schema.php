<?php
/**
 * ESQUEMA — una sola definición para MySQL (producción) y SQLite (prueba local).
 *
 * Tokens que se traducen según el motor:
 *   {PK}  -> clave primaria autoincremental
 * Prefijos por módulo: seg_ (seguridad), cat_ (catálogos internos), ref_ (bases
 * externas versionadas), nt_ (Maestra Tarifaria), proc_ (lotes y validaciones),
 * prod_ (productos creados), aud_ (trazabilidad).
 *
 * Los catálogos se relacionan por su CÓDIGO de negocio (no por id) para que el
 * histórico de productos no dependa de que un catálogo se recargue.
 */

function esquema_tablas(): array {
    return [
        // ---------------- SEGURIDAD: USUARIOS -> PERFILES -> PERMISOS -> MÓDULOS
        'seg_modulo' => "
            id {PK},
            codigo VARCHAR(40) NOT NULL UNIQUE,
            nombre VARCHAR(100) NOT NULL,
            orden INT NOT NULL DEFAULT 0,
            estado VARCHAR(20) NOT NULL DEFAULT 'ACTIVO'",
        'seg_permiso' => "
            id {PK},
            modulo_id INT NOT NULL,
            codigo VARCHAR(60) NOT NULL UNIQUE,
            nombre VARCHAR(150) NOT NULL,
            FOREIGN KEY (modulo_id) REFERENCES seg_modulo(id)",
        'seg_perfil' => "
            id {PK},
            codigo VARCHAR(40) NOT NULL UNIQUE,
            nombre VARCHAR(100) NOT NULL,
            descripcion VARCHAR(255),
            activo TINYINT NOT NULL DEFAULT 1",
        'seg_perfil_permiso' => "
            perfil_id INT NOT NULL,
            permiso_id INT NOT NULL,
            PRIMARY KEY (perfil_id, permiso_id),
            FOREIGN KEY (perfil_id) REFERENCES seg_perfil(id),
            FOREIGN KEY (permiso_id) REFERENCES seg_permiso(id)",
        'seg_usuario' => "
            id {PK},
            usuario VARCHAR(80) NOT NULL UNIQUE,
            nombre VARCHAR(150) NOT NULL,
            correo VARCHAR(150),
            password_hash VARCHAR(255) NOT NULL,
            activo TINYINT NOT NULL DEFAULT 1,
            debe_cambiar_clave TINYINT NOT NULL DEFAULT 1,
            intentos_fallidos INT NOT NULL DEFAULT 0,
            bloqueado_hasta DATETIME NULL,
            ultimo_acceso DATETIME NULL,
            creado_en DATETIME NOT NULL,
            creado_por INT NULL",
        'seg_usuario_perfil' => "
            usuario_id INT NOT NULL,
            perfil_id INT NOT NULL,
            PRIMARY KEY (usuario_id, perfil_id),
            FOREIGN KEY (usuario_id) REFERENCES seg_usuario(id),
            FOREIGN KEY (perfil_id) REFERENCES seg_perfil(id)",

        // ---------------- CATÁLOGOS INTERNOS (hoy hojas del Excel CREAR ITEMS)
        'cat_molecula' => "
            id {PK},
            cod_gen_audifarma VARCHAR(20) NOT NULL UNIQUE,
            descripcion_general VARCHAR(400),
            codigo_medicar VARCHAR(20),
            pos_no_pos VARCHAR(20),
            concentracion_agrupada VARCHAR(150),
            cantidad_tope VARCHAR(20),
            principio_activo VARCHAR(300),
            forma_farmaceutica VARCHAR(150),
            grupo_farmacologico VARCHAR(150),
            via_administracion VARCHAR(100),
            cadena_frio VARCHAR(10),
            activo TINYINT NOT NULL DEFAULT 1,
            actualizado_en DATETIME NOT NULL",
        'cat_laboratorio' => "
            id {PK},
            nombre VARCHAR(250) NOT NULL UNIQUE,
            codigo VARCHAR(20) NOT NULL UNIQUE,
            activo TINYINT NOT NULL DEFAULT 1,
            actualizado_en DATETIME NOT NULL",
        'cat_presentacion' => "
            id {PK},
            presentacion_agrupada VARCHAR(100) NOT NULL UNIQUE,
            codigo VARCHAR(20) NOT NULL UNIQUE,
            unidad_orden VARCHAR(20) NOT NULL, -- NO es única: p.ej. 'CAJA X 300' y 'CAJA SELLADA X 300' comparten C300
            activo TINYINT NOT NULL DEFAULT 1,
            actualizado_en DATETIME NOT NULL",
        // Catálogos de LISTAS DESPLEGABLES del formulario de creación (Tipo de medicamento, Envase...).
        // El Administrador puede crear nuevas; las de sistema (del Excel) no se eliminan.
        'cat_lista' => "
            codigo VARCHAR(40) NOT NULL PRIMARY KEY,
            nombre VARCHAR(100) NOT NULL UNIQUE,
            descripcion VARCHAR(300),
            es_sistema TINYINT NOT NULL DEFAULT 0,
            activo TINYINT NOT NULL DEFAULT 1,
            orden INT NOT NULL DEFAULT 0,
            creado_en DATETIME NOT NULL,
            creado_por INT NULL",
        'cat_lista_valor' => "
            id {PK},
            lista VARCHAR(40) NOT NULL,
            valor VARCHAR(150) NOT NULL,
            orden INT NOT NULL DEFAULT 0,
            activo TINYINT NOT NULL DEFAULT 1,
            UNIQUE (lista, valor)",
        'cat_grupo_impositivo' => "
            codigo VARCHAR(10) NOT NULL PRIMARY KEY,
            etiqueta_iva VARCHAR(20) NOT NULL UNIQUE,
            descripcion VARCHAR(150) NOT NULL,
            iva_medicar INT NOT NULL",
        'cat_tipo_inventario' => "
            codigo VARCHAR(20) NOT NULL PRIMARY KEY,
            tipo_medicamento VARCHAR(40) NOT NULL UNIQUE,
            descripcion VARCHAR(150) NOT NULL",
        'cat_subplan' => "
            id_subplan INT NOT NULL PRIMARY KEY,
            nombre VARCHAR(150) NOT NULL UNIQUE,
            clasificacion VARCHAR(10) NULL,
            fuente VARCHAR(40) NULL,
            activo TINYINT NOT NULL DEFAULT 1,
            actualizado_en DATETIME NOT NULL,
            actualizado_por INT NULL",
        'cat_rips' => "
            id {PK},
            tabla VARCHAR(5) NOT NULL,
            codigo VARCHAR(30) NOT NULL,
            nombre VARCHAR(300) NOT NULL,
            habilitado TINYINT NOT NULL DEFAULT 1,
            UNIQUE (tabla, codigo)",
        'cat_ium' => "
            codigo VARCHAR(20) NOT NULL PRIMARY KEY,
            nombre VARCHAR(500)",
        'cat_campo_tecnico' => "
            id {PK},
            f104_id VARCHAR(80) NOT NULL UNIQUE,
            orden INT NOT NULL,
            activo TINYINT NOT NULL DEFAULT 1",
        // Decisiones sobre hallazgos de catálogos (p.ej. laboratorio con dos códigos): quién y qué decidió.
        'cat_decision' => "
            id {PK},
            catalogo VARCHAR(40) NOT NULL,
            hallazgo VARCHAR(500) NOT NULL,
            decision VARCHAR(500) NOT NULL,
            decidido_por INT NULL,
            decidido_en DATETIME NOT NULL",
        'cat_parametro' => "
            clave VARCHAR(60) NOT NULL PRIMARY KEY,
            valor VARCHAR(500) NOT NULL,
            descripcion VARCHAR(255),
            actualizado_en DATETIME NOT NULL,
            actualizado_por INT NULL",

        // ---------------- BASES EXTERNAS (definición configurable + cargas versionadas)
        'ref_base' => "
            codigo VARCHAR(40) NOT NULL PRIMARY KEY,
            nombre VARCHAR(150) NOT NULL,
            descripcion VARCHAR(300),
            modo VARCHAR(20) NOT NULL,
            guarda_versiones TINYINT NOT NULL DEFAULT 0,
            fase_automatica INT NULL,
            extensiones VARCHAR(60) NOT NULL DEFAULT 'xlsx,xlsm,csv',
            orden INT NOT NULL DEFAULT 0,
            activo TINYINT NOT NULL DEFAULT 1",
        'ref_carga' => "
            id {PK},
            tipo_base VARCHAR(30) NOT NULL,
            origen VARCHAR(300),
            hash_sha256 VARCHAR(64),
            filas INT NOT NULL DEFAULT 0,
            estado VARCHAR(20) NOT NULL,
            vigente TINYINT NOT NULL DEFAULT 0,
            detalle TEXT,
            usuario_id INT NULL,
            creado_en DATETIME NOT NULL",
        // Bases manuales leídas (fase 3). Cada fila pertenece a una carga (versión) de ref_carga.
        'ref_siesa_item' => "
            id {PK},
            carga_id INT NOT NULL,
            item VARCHAR(20),
            referencia VARCHAR(40),
            cum VARCHAR(40),
            registro_sanitario VARCHAR(60),
            descripcion VARCHAR(500),
            contrato VARCHAR(30),
            unidad_orden VARCHAR(20),
            laboratorio VARCHAR(250),
            cod_gen_audifarma VARCHAR(20),
            id_mutual VARCHAR(40),
            tipo_inventario VARCHAR(20),
            grupo_impositivo VARCHAR(10),
            estado VARCHAR(30),
            tipo_medicamento VARCHAR(40),
            codigo_barra VARCHAR(60),
            forma_farmaceutica_rips VARCHAR(30),
            concentracion_rips VARCHAR(30),
            unidad_medida_rips VARCHAR(30),
            codigo_generico_auxiliar VARCHAR(40),
            codigo_atc VARCHAR(60),
            unidad_inventario VARCHAR(20),
            unidad_presentacion VARCHAR(20),
            FOREIGN KEY (carga_id) REFERENCES ref_carga(id)",
        'ref_medicar' => "
            id {PK},
            carga_id INT NOT NULL,
            codigo_molecula VARCHAR(20),
            molecula VARCHAR(400),
            plu_cadena VARCHAR(40),
            descripcion_articulo VARCHAR(500),
            cum VARCHAR(40),
            laboratorio VARCHAR(250),
            subplan VARCHAR(150),
            id_subplan VARCHAR(10),
            codigo_generico VARCHAR(40),
            opcion VARCHAR(4),
            precio_um_iva VARCHAR(30),
            iva_pct VARCHAR(10),
            codigo_md VARCHAR(40),
            registro_invima VARCHAR(60),
            tipo_medicamento VARCHAR(40),
            FOREIGN KEY (carga_id) REFERENCES ref_carga(id)",
        'ref_capita' => "
            id {PK},
            carga_id INT NOT NULL,
            cum VARCHAR(40),
            id_mutual VARCHAR(40),
            codigo_cup VARCHAR(20),
            tipo_servicio VARCHAR(60),
            codigo_servicio VARCHAR(20),
            descripcion_cups VARCHAR(500),
            tarifa_pactada DECIMAL(16,2),
            invima VARCHAR(60),
            fuente_financiacion VARCHAR(100),
            regulado VARCHAR(20),
            FOREIGN KEY (carga_id) REFERENCES ref_carga(id)",
        'ref_evento_sas' => "
            id {PK},
            carga_id INT NOT NULL,
            cod_audifarma VARCHAR(20),
            subcuenta VARCHAR(60),
            cum VARCHAR(40),
            registro_sanitario VARCHAR(60),
            descripcion VARCHAR(500),
            clasificacion VARCHAR(60),
            id_mutual VARCHAR(40),
            id_mutual_capita VARCHAR(40),
            cod_medicar VARCHAR(20),
            FOREIGN KEY (carga_id) REFERENCES ref_carga(id)",
        'ref_contrato_evento' => "
            id {PK},
            carga_id INT NOT NULL,
            numero_contrato VARCHAR(20),
            cod_servicio VARCHAR(20),
            servicio VARCHAR(150),
            id_mutual VARCHAR(40),
            producto VARCHAR(500),
            estado VARCHAR(5),
            valor_unitario DECIMAL(16,2),
            FOREIGN KEY (carga_id) REFERENCES ref_carga(id)",
        // Homologación Mutual Ser código <-> CUM (CUMS_PHARMASER.csv). Un código agrupa varios CUM
        // y un CUM puede estar en varios códigos.
        'ref_cums_mutual' => "
            id {PK},
            carga_id INT NOT NULL,
            cod_mutual VARCHAR(40),
            nombre VARCHAR(500),
            principio VARCHAR(300),
            cum VARCHAR(40),
            laboratorio VARCHAR(300),
            registro_sanitario VARCHAR(120),
            descripcion VARCHAR(500),
            clasificacion VARCHAR(10),
            FOREIGN KEY (carga_id) REFERENCES ref_carga(id)",
        // Precio unitario regulado (circular de precios) por código genérico.
        'ref_precio_regulado' => "
            id {PK},
            carga_id INT NOT NULL,
            cod_gen VARCHAR(20),
            nombre VARCHAR(500),
            precio_regulado DECIMAL(16,2),
            FOREIGN KEY (carga_id) REFERENCES ref_carga(id)",
        // Consulta de medicamentos de Mutual Ser: qué CUM está asociado a cada ID MUTUAL (P2).
        'ref_medicamentos_mutual' => "
            id {PK},
            carga_id INT NOT NULL,
            id_mutual VARCHAR(40),
            cod_gen_audifarma VARCHAR(20),
            cum VARCHAR(40),
            estado_cum VARCHAR(20),
            estado VARCHAR(20),
            registro_sanitario VARCHAR(120),
            estado_registro VARCHAR(60),
            medicamento VARCHAR(500),
            presentacion VARCHAR(500),
            FOREIGN KEY (carga_id) REFERENCES ref_carga(id)",
        // Área de IMPORTACIÓN de la Maestra Tarifaria desde Excel (vista previa antes de aplicar).
        'ref_nt_item' => "
            id {PK},
            carga_id INT NOT NULL,
            fecha VARCHAR(10),
            cum VARCHAR(60),
            cum_facturar VARCHAR(60),
            contrato VARCHAR(12),
            cod_auf VARCHAR(20),
            tipo_producto VARCHAR(10),
            descripcion VARCHAR(500),
            cod_gen_siesa VARCHAR(20),
            nuevo_cod_mutual VARCHAR(40),
            cod_mutual VARCHAR(40),
            cod_mutual_final VARCHAR(40),
            precio_venta DECIMAL(16,2),
            iva DECIMAL(6,4),
            precio_m_archivo DECIMAL(16,2),
            precio_final_archivo DECIMAL(16,2),
            FOREIGN KEY (carga_id) REFERENCES ref_carga(id)",
        'ref_invima' => "
            id {PK},
            carga_id INT NOT NULL,
            registro_sanitario VARCHAR(60),
            estado_registro VARCHAR(60),
            cum VARCHAR(40),
            estado_cum VARCHAR(30),
            producto VARCHAR(500),
            fuente VARCHAR(30),
            FOREIGN KEY (carga_id) REFERENCES ref_carga(id)",

        // INVIMA consolidado (fase 3.8): un estado por CUM y uno por registro sanitario, según prioridad.
        // ref_invima es el área temporal de descarga; se vacía después de consolidar.
        'ref_invima_cum' => "
            id {PK},
            carga_id INT NOT NULL,
            cum VARCHAR(40) NOT NULL,
            estado_cum VARCHAR(30),
            registro_sanitario VARCHAR(60),
            estado_registro VARCHAR(60),
            producto VARCHAR(500),
            titular VARCHAR(300),
            fuente VARCHAR(20),
            FOREIGN KEY (carga_id) REFERENCES ref_carga(id)",
        'ref_invima_registro' => "
            id {PK},
            carga_id INT NOT NULL,
            registro_sanitario VARCHAR(60) NOT NULL,
            estado_registro VARCHAR(60),
            fecha_vencimiento VARCHAR(20),
            producto VARCHAR(500),
            titular VARCHAR(300),
            grupo VARCHAR(100),
            fuente VARCHAR(20),
            FOREIGN KEY (carga_id) REFERENCES ref_carga(id)",

        // ---------------- PROCESOS EN SEGUNDO PLANO (p.ej. descarga INVIMA): avance visible en pantalla
        'sis_proceso' => "
            id {PK},
            tipo VARCHAR(30) NOT NULL,
            estado VARCHAR(20) NOT NULL,
            avance INT NOT NULL DEFAULT 0,
            mensaje VARCHAR(500),
            detalle TEXT,
            usuario_id INT NULL,
            iniciado_en DATETIME NOT NULL,
            actualizado_en DATETIME NOT NULL,
            terminado_en DATETIME NULL",

        // ---------------- MAESTRA TARIFARIA (se mantiene en el aplicativo; se exporta a Excel para compartirla)
        // Un producto por CUM a facturar. Lo calculado (contrato, validadores, precio + IVA, ...) no se guarda:
        // lo recalcula el motor contra las bases vigentes.
        // Una línea por CUM a facturar y CONTRATO (CAPITA / EVENTO / EVENTO SAS): el mismo producto se repite
        // en cada contrato donde está, con su propio precio, y se valida contra la base de ese contrato.
        'nt_item' => "
            id {PK},
            cum_facturar VARCHAR(60) NOT NULL,
            contrato VARCHAR(12) NOT NULL DEFAULT 'EVENTO',
            cum VARCHAR(60),
            fecha VARCHAR(10),
            cod_auf VARCHAR(20),
            tipo_producto VARCHAR(10),
            descripcion VARCHAR(500),
            cod_gen_siesa VARCHAR(20),
            nuevo_cod_mutual VARCHAR(40),
            cod_mutual VARCHAR(40),
            cod_mutual_final VARCHAR(40),
            precio_venta DECIMAL(16,2),
            iva DECIMAL(6,4),
            origen_precio VARCHAR(20),
            activo TINYINT NOT NULL DEFAULT 1,
            creado_en DATETIME NOT NULL,
            creado_por INT NULL,
            actualizado_en DATETIME NULL,
            actualizado_por INT NULL,
            precio1_aprobado_por INT NULL,
            precio1_aprobado_en DATETIME NULL,
            cum_validado_por INT NULL,
            cum_validado_en DATETIME NULL,
            UNIQUE (cum_facturar, contrato)",
        // Líneas quitadas de la Maestra Tarifaria (p.ej. inactivas en MEDICAR, fase 7). Quedan en la versión anterior de la Maestra Tarifaria
        // (ref_carga, estado VERSION_ANTERIOR) y no se vuelven a proponer en «otros contratos» ni en «Por agregar».
        'nt_quitada' => "
            id {PK},
            cum_facturar VARCHAR(60) NOT NULL,
            contrato VARCHAR(12) NOT NULL,
            cod_auf VARCHAR(20),
            motivo VARCHAR(500),
            carga_id INT NULL,
            datos TEXT,
            quitado_por INT NULL,
            quitado_en DATETIME NOT NULL",
        // Estado por PLU + CONTRATO (pedido del usuario, 2026-10-26): una línea (CUM FACTURAR + contrato) con varios PLU de SIESA puede
        // tener un PLU inactivo y otro activo. Solo se guardan los PLU inactivos de una línea activa; la línea inactiva inactiva todos.
        'nt_item_plu' => "
            id {PK},
            nt_item_id INT NOT NULL,
            plu VARCHAR(40) NOT NULL,
            activo TINYINT NOT NULL DEFAULT 0,
            actualizado_por INT NULL,
            actualizado_en DATETIME NOT NULL",
        // Cambios de una versión de una base de referencia frente a la anterior (por la llave de la base):
        // NUEVO / RETIRADO (una fila por registro) y MODIFICADO (una fila por campo con antes y después).
        'ref_carga_cambio' => "
            id {PK},
            carga_id INT NOT NULL,
            carga_anterior_id INT NULL,
            tipo VARCHAR(12) NOT NULL,
            llave VARCHAR(250) NOT NULL,
            campo VARCHAR(60) NULL,
            antes VARCHAR(500) NULL,
            despues VARCHAR(500) NULL",
        // Calculadora de precio (DOC CRIS): cada cálculo guardado en la Maestra Tarifaria queda con sus datos de entrada.
        'nt_precio_calculo' => "
            id {PK},
            nt_item_id INT NOT NULL,
            precio_compra DECIMAL(16,2),
            porcentaje DECIMAL(6,2),
            precio_regulado DECIMAL(16,2),
            precio_capita DECIMAL(16,2),
            precio_resultado DECIMAL(16,2) NOT NULL,
            regla VARCHAR(200),
            usuario_id INT NULL,
            creado_en DATETIME NOT NULL,
            FOREIGN KEY (nt_item_id) REFERENCES nt_item(id)",
        // Producto creado <-> fila de la Maestra Tarifaria (precio / ID MUTUAL asignado)
        'nt_producto' => "
            id {PK},
            producto_id INT NOT NULL,
            nt_item_id INT NULL,
            id_mutual VARCHAR(40),
            precio DECIMAL(16,2),
            estado VARCHAR(20) NOT NULL DEFAULT 'PROPUESTO',
            asignado_por INT NULL,
            asignado_en DATETIME NULL,
            aprobado_por INT NULL,
            aprobado_en DATETIME NULL,
            observacion VARCHAR(500),
            FOREIGN KEY (producto_id) REFERENCES prod_producto(id),
            FOREIGN KEY (nt_item_id) REFERENCES nt_item(id)",

        // ---------------- PROCESO: LOTES, ÍTEMS Y RESULTADO DE VALIDACIONES
        'proc_lote' => "
            id {PK},
            estado VARCHAR(20) NOT NULL DEFAULT 'BORRADOR',
            observacion VARCHAR(500),
            creado_por INT NOT NULL,
            creado_en DATETIME NOT NULL,
            validado_en DATETIME NULL,
            cerrado_en DATETIME NULL",
        'proc_lote_item' => "
            id {PK},
            lote_id INT NOT NULL,
            fila INT NOT NULL,
            codigo_generico_audifarma VARCHAR(20),
            iva VARCHAR(20),
            contrato_medicar VARCHAR(150),
            cum VARCHAR(40),
            codigo_cum_auxiliar VARCHAR(40),
            registro_sanitario VARCHAR(60),
            codigo_ium VARCHAR(40),
            nombre_comercial VARCHAR(250),
            laboratorio VARCHAR(250),
            codigo_atc VARCHAR(20),
            tipo_medicamento VARCHAR(40),
            fecha_reg_invima DATE NULL,
            estado_invima VARCHAR(60),
            presentacion_1 VARCHAR(60),
            unidad_presentacion INT NULL,
            categoria_pbs VARCHAR(20),
            forma_farmaceutica_rips VARCHAR(30),
            concentracion_rips VARCHAR(30),
            unidad_medida_rips VARCHAR(30),
            ean VARCHAR(40),
            derivados TEXT,
            codigo_producto_completo VARCHAR(40),
            tipo_operacion VARCHAR(20),
            resultado VARCHAR(20),
            excepcion_precio_por INT NULL,
            excepcion_precio_motivo VARCHAR(500),
            actualizado_en DATETIME NOT NULL,
            FOREIGN KEY (lote_id) REFERENCES proc_lote(id)",
        // Aprobaciones del Administrador que pide el motor de la solicitud (fase 5): crear molécula / laboratorio /
        // presentación, asignar POS / NO POS (COD GEN en «ASIGNAR») e incluir el producto en la Maestra Tarifaria.
        'proc_aprobacion' => "
            id {PK},
            lote_item_id INT NOT NULL,
            tipo VARCHAR(20) NOT NULL,
            clave VARCHAR(150) NOT NULL,
            datos TEXT,
            estado VARCHAR(12) NOT NULL DEFAULT 'PENDIENTE',
            solicitado_por INT NULL,
            solicitado_en DATETIME NOT NULL,
            resuelto_por INT NULL,
            resuelto_en DATETIME NULL,
            motivo VARCHAR(500),
            FOREIGN KEY (lote_item_id) REFERENCES proc_lote_item(id)",
        'proc_regla' => "
            codigo VARCHAR(20) NOT NULL PRIMARY KEY,
            descripcion VARCHAR(300) NOT NULL,
            severidad VARCHAR(10) NOT NULL,
            base_comparada VARCHAR(80),
            accion_sugerida VARCHAR(300),
            activa TINYINT NOT NULL DEFAULT 1,
            orden INT NOT NULL DEFAULT 0",
        'proc_resultado' => "
            id {PK},
            lote_item_id INT NOT NULL,
            regla_codigo VARCHAR(20) NOT NULL,
            severidad VARCHAR(10) NOT NULL,
            campo VARCHAR(80),
            base_comparada VARCHAR(80),
            valor_encontrado VARCHAR(500),
            valor_esperado VARCHAR(500),
            accion VARCHAR(300),
            creado_en DATETIME NOT NULL,
            FOREIGN KEY (lote_item_id) REFERENCES proc_lote_item(id)",

        // ---------------- PRODUCTOS CREADOS
        'prod_producto' => "
            id {PK},
            codigo_producto_completo VARCHAR(40) NOT NULL UNIQUE,
            f120_id VARCHAR(20) NULL UNIQUE,
            cum VARCHAR(40),
            registro_sanitario VARCHAR(60),
            descripcion VARCHAR(400),
            laboratorio VARCHAR(250),
            tipo_medicamento VARCHAR(40),
            id_mutual VARCHAR(40),
            precio DECIMAL(16,2),
            estado VARCHAR(30) NOT NULL DEFAULT 'PENDIENTE_ID_SIESA',
            datos TEXT,
            lote_item_id INT NULL,
            ejecucion_id INT NULL,
            creado_por INT NOT NULL,
            creado_en DATETIME NOT NULL,
            actualizado_en DATETIME NOT NULL",
        'prod_molecula_medicar' => "
            codigo_molecula VARCHAR(20) NOT NULL PRIMARY KEY,
            fuente VARCHAR(30) NOT NULL,
            creado_en DATETIME NOT NULL",

        // ---------------- CORRECCIONES SIESA / MEDICAR (2026-10-19): inconsistencias frente a la Maestra Tarifaria y su estado
        'cor_inconsistencia' => "
            id {PK},
            sistema VARCHAR(10) NOT NULL,
            llave VARCHAR(250) NOT NULL,
            cum_facturar VARCHAR(60),
            plu VARCHAR(40),
            item VARCHAR(20),
            contratos VARCHAR(60),
            subplan VARCHAR(60),
            campo VARCHAR(20) NOT NULL,
            valor_actual VARCHAR(500),
            valor_esperado VARCHAR(500),
            accion VARCHAR(500),
            cargue VARCHAR(20),
            estado VARCHAR(30) NOT NULL,
            detectado_en DATETIME NOT NULL,
            actualizado_en DATETIME NULL,
            exportacion_id INT NULL,
            carga_exportada INT NULL,
            exportado_en DATETIME NULL,
            exportado_por INT NULL,
            cerrado_en DATETIME NULL",
        // CUM que sí deben tener varios PLU activos en SIESA, revisados y aprobados (pedido del usuario, 2026-11-05): no generan
        // «Más de un PLU con el mismo CUM» ni NT29 mientras sus PLU activos sean los aprobados (si SIESA activa otro, vuelve el error).
        'cor_aprobacion' => "
            id {PK},
            cum_facturar VARCHAR(60) NOT NULL,
            campo VARCHAR(30) NOT NULL,
            plus VARCHAR(500) NOT NULL,
            motivo VARCHAR(300) NOT NULL,
            activo TINYINT NOT NULL DEFAULT 1,
            aprobado_por INT NULL,
            aprobado_en DATETIME NOT NULL,
            retirado_por INT NULL,
            retirado_en DATETIME NULL",
        'cor_revision' => "
            id {PK},
            sistema VARCHAR(10) NOT NULL,
            carga_id INT NULL,
            nuevas INT NOT NULL DEFAULT 0,
            corregidas INT NOT NULL DEFAULT 0,
            no_validadas INT NOT NULL DEFAULT 0,
            abiertas INT NOT NULL DEFAULT 0,
            usuario_id INT NULL,
            creado_en DATETIME NOT NULL",
        'cor_exportacion' => "
            id {PK},
            sistema VARCHAR(10) NOT NULL,
            filas INT NOT NULL DEFAULT 0,
            archivos TEXT,
            ruta VARCHAR(500),
            carga_id INT NULL,
            usuario_id INT NULL,
            creado_en DATETIME NOT NULL",

        // ---------------- CONTROL DE MIGRACIONES DE DATOS (cada una se aplica una sola vez)
        'sis_migracion' => "
            codigo VARCHAR(60) NOT NULL PRIMARY KEY,
            descripcion VARCHAR(300),
            aplicada_en DATETIME NOT NULL",

        // ---------------- TRAZABILIDAD
        'aud_ejecucion' => "
            id {PK},
            lote_id INT NOT NULL,
            usuario_id INT NOT NULL,
            inicio DATETIME NOT NULL,
            fin DATETIME NULL,
            procesados INT NOT NULL DEFAULT 0,
            creados INT NOT NULL DEFAULT 0,
            rechazados INT NOT NULL DEFAULT 0,
            alertas INT NOT NULL DEFAULT 0,
            resultado VARCHAR(20),
            detalle TEXT",
        'aud_ejecucion_item' => "
            id {PK},
            ejecucion_id INT NOT NULL,
            lote_item_id INT NOT NULL,
            resultado VARCHAR(20) NOT NULL,
            motivo VARCHAR(1000),
            FOREIGN KEY (ejecucion_id) REFERENCES aud_ejecucion(id)",
        'aud_archivo' => "
            id {PK},
            ejecucion_id INT NOT NULL,
            nombre VARCHAR(200) NOT NULL,
            hoja VARCHAR(60),
            filas INT NOT NULL DEFAULT 0,
            hash_sha256 VARCHAR(64),
            ruta VARCHAR(500),
            creado_en DATETIME NOT NULL,
            FOREIGN KEY (ejecucion_id) REFERENCES aud_ejecucion(id)",
        'aud_log' => "
            id {PK},
            usuario_id INT NULL,
            accion VARCHAR(60) NOT NULL,
            entidad VARCHAR(60),
            entidad_id VARCHAR(60),
            antes TEXT,
            despues TEXT,
            ip VARCHAR(45),
            creado_en DATETIME NOT NULL",
    ];
}

/** Índices para las búsquedas que hacen las validaciones (cruces por conjunto, no fila a fila). */
function esquema_indices(): array {
    return [
        ['idx_mol_medicar',   'cat_molecula',      'codigo_medicar'],
        ['idx_pres_unidad',   'cat_presentacion',  'unidad_orden'],
        ['idx_si_carga_ref',  'ref_siesa_item',    'carga_id, referencia'],
        ['idx_si_carga_cum',  'ref_siesa_item',    'carga_id, cum'],
        ['idx_si_carga_reg',  'ref_siesa_item',    'carga_id, registro_sanitario'],
        ['idx_si_carga_mut',  'ref_siesa_item',    'carga_id, id_mutual'],
        ['idx_me_carga_mol',  'ref_medicar',       'carga_id, codigo_molecula'],
        ['idx_me_carga_plu',  'ref_medicar',       'carga_id, plu_cadena'],
        ['idx_me_carga_cum',  'ref_medicar',       'carga_id, cum'],
        ['idx_ca_carga_mut',  'ref_capita',        'carga_id, id_mutual, codigo_cup'],
        ['idx_ca_carga_cum',  'ref_capita',        'carga_id, cum'],
        ['idx_es_carga_mut',  'ref_evento_sas',    'carga_id, id_mutual'],
        ['idx_es_carga_cod',  'ref_evento_sas',    'carga_id, cod_audifarma'],
        ['idx_es_carga_cum',  'ref_evento_sas',    'carga_id, cum'],
        ['idx_ce_carga_mut',  'ref_contrato_evento','carga_id, id_mutual'],
        ['idx_inv_carga_cum', 'ref_invima',        'carga_id, cum'],
        ['idx_inv_carga_reg', 'ref_invima',        'carga_id, registro_sanitario'],
        ['idx_ic_carga_cum',  'ref_invima_cum',    'carga_id, cum'],
        ['idx_ic_carga_reg',  'ref_invima_cum',    'carga_id, registro_sanitario'],
        ['idx_ir_carga_reg',  'ref_invima_registro','carga_id, registro_sanitario'],
        ['idx_proc_tipo',     'sis_proceso',       'tipo, estado'],
        ['idx_carga_tipo',    'ref_carga',         'tipo_base, vigente'],
        ['idx_carga_hash',    'ref_carga',         'tipo_base, hash_sha256'],
        ['idx_ntr_cum',       'ref_nt_item',       'carga_id, cum_facturar'],
        ['idx_ntr_final',     'ref_nt_item',       'carga_id, cod_mutual_final'],
        ['idx_ntr_mutual',    'ref_nt_item',       'carga_id, cod_mutual'],
        ['idx_ntr_auf',       'ref_nt_item',       'carga_id, cod_auf'],
        ['idx_cm_carga_cum',  'ref_cums_mutual',   'carga_id, cum'],
        ['idx_cm_carga_cod',  'ref_cums_mutual',   'carga_id, cod_mutual'],
        ['idx_pr_carga_gen',  'ref_precio_regulado','carga_id, cod_gen'],
        ['idx_mm_carga_cum',  'ref_medicamentos_mutual','carga_id, cum'],
        ['idx_mm_carga_id',   'ref_medicamentos_mutual','carga_id, id_mutual'],
        ['idx_nti_mutual',    'nt_item',           'cod_mutual'],
        ['idx_nti_auf',       'nt_item',           'cod_auf'],
        ['idx_ntc_item',      'nt_precio_calculo', 'nt_item_id'],
        ['idx_ntq_linea',     'nt_quitada',        'cum_facturar, contrato'],
        ['idx_ntp_item',      'nt_item_plu',       'nt_item_id, plu'],
        ['idx_cora_cum',      'cor_aprobacion',    'cum_facturar, campo'],
        ['idx_rcc_carga',     'ref_carga_cambio',  'carga_id'],
        ['idx_cor_estado',    'cor_inconsistencia','sistema, estado'],
        ['idx_cor_llave',     'cor_inconsistencia','llave'],
        ['idx_item_lote',     'proc_lote_item',    'lote_id'],
        ['idx_item_codigo',   'proc_lote_item',    'codigo_producto_completo'],
        ['idx_res_item',      'proc_resultado',    'lote_item_id'],
        ['idx_prod_cum',      'prod_producto',     'cum'],
        ['idx_log_fecha',     'aud_log',           'creado_en'],
        ['idx_eje_lote',      'aud_ejecucion',     'lote_id'],
    ];
}

/**
 * Columnas agregadas después de la primera instalación: se añaden con ALTER TABLE si faltan,
 * para no reinstalar la base (ni perder datos) en cada ajuste. [tabla, columna, definición]
 */
function esquema_columnas_nuevas(): array {
    return [
        ['ref_carga', 'version',      'INT NULL'],
        ['ref_carga', 'archivo',      'VARCHAR(500) NULL'],
        ['ref_carga', 'tamano_bytes', 'INT NULL'],
        // Todos los catálogos se desactivan en vez de borrarse
        ['cat_grupo_impositivo', 'activo', 'TINYINT NOT NULL DEFAULT 1'],
        ['cat_tipo_inventario',  'activo', 'TINYINT NOT NULL DEFAULT 1'],
        ['cat_ium',              'activo', 'TINYINT NOT NULL DEFAULT 1'],
        // Fase 3: alerta de base desactualizada y resultado de la lectura
        ['ref_base',  'dias_alerta', 'INT NOT NULL DEFAULT 30'],
        ['ref_carga', 'leido_en',    'DATETIME NULL'],
        ['ref_carga', 'omitidas',    'INT NOT NULL DEFAULT 0'],
        // Área temporal de descarga INVIMA
        ['ref_invima', 'titular',           'VARCHAR(300) NULL'],
        ['ref_invima', 'fecha_vencimiento', 'VARCHAR(20) NULL'],
        ['ref_invima', 'grupo',             'VARCHAR(100) NULL'],
        ['ref_invima', 'prioridad_cum',     'INT NULL'],
        ['ref_invima', 'prioridad_reg',     'INT NULL'],
        // Maestra Mutual Ser: mismas columnas que selecciona el script MaestraMutualser.R
        ['ref_medicamentos_mutual', 'codigo_atc',       'VARCHAR(120) NULL'], // puede traer varios ATC (p.ej. «V08AA05, C05CX03»)
        ['ref_medicamentos_mutual', 'descripcion_atc',  'VARCHAR(300) NULL'],
        ['ref_medicamentos_mutual', 'medicamento_dci',  'VARCHAR(500) NULL'],
        ['ref_medicamentos_mutual', 'financiacion',     'VARCHAR(100) NULL'],
        ['ref_medicamentos_mutual', 'nombre_comercial', 'VARCHAR(300) NULL'],
        ['ref_medicamentos_mutual', 'expediente',       'VARCHAR(40) NULL'],
        ['ref_medicamentos_mutual', 'fecha_vencimiento','VARCHAR(10) NULL'],
        ['ref_medicamentos_mutual', 'codigo_ium',       'VARCHAR(60) NULL'],
        ['ref_medicamentos_mutual', 'valor_referencia', 'DECIMAL(16,2) NULL'],
        ['ref_nt_item',             'contrato',         'VARCHAR(12) NULL'],
        // MEDICAR: Opcion Cadena (1 = activo, 9 = inactivo), para validar CUM inactivos (NT24)
        ['ref_medicar',             'opcion',           'VARCHAR(4) NULL'],
        // Fase 5: lo calculado por el motor y los datos propuestos para las aprobaciones
        ['proc_lote_item',          'datos_calculados', 'TEXT NULL'],
        ['proc_lote_item',          'datos_propuestos', 'TEXT NULL'],
        // columnas calculadas que trae el archivo: no se cargan, solo se comparan para avisar si se editaron
        ['ref_nt_item',             'precio_m_archivo',     'DECIMAL(16,2) NULL'],
        ['ref_nt_item',             'precio_final_archivo', 'DECIMAL(16,2) NULL'],
        // Excepción de precio 1 aprobada por el Administrador (se pierde si el precio cambia)
        ['nt_item',                 'precio1_aprobado_por', 'INT NULL'],
        ['nt_item',                 'precio1_aprobado_en',  'DATETIME NULL'],
        // CUM no encontrado en INVIMA validado a mano por el Administrador (NT10 deja de salir)
        ['nt_item',                 'cum_validado_por',     'INT NULL'],
        ['nt_item',                 'cum_validado_en',      'DATETIME NULL'],
        // NT: subplán leído del Excel (CONTRATO EVENTO + subplán SAS = línea EVENTO SAS), 2026-10-14
        ['ref_nt_item',             'subplan_archivo',         'VARCHAR(60) NULL'],
        ['ref_nt_item',             'plu_archivo',             'VARCHAR(60) NULL'], // un PLU por fila en el Excel (2026-10-19)
        // Reglas personalizadas por el Administrador (2026-10-13): ámbito, campo, condición y valor
        ['proc_regla',              'personalizada',           'TINYINT NOT NULL DEFAULT 0'],
        ['proc_regla',              'ambito',                  'VARCHAR(20) NULL'],
        ['proc_regla',              'campo',                   'VARCHAR(60) NULL'],
        ['proc_regla',              'condicion',               'VARCHAR(20) NULL'],
        ['proc_regla',              'valor',                   'VARCHAR(500) NULL'],
        ['proc_regla',              'creado_por',              'INT NULL'],
        ['proc_regla',              'creado_en',               'DATETIME NULL'],
        // Fase 7D: clasificación del contrato (CAPITA / EVENTO) y fuente de precio de cada subplán
        ['cat_subplan',             'clasificacion',           'VARCHAR(10) NULL'],
        ['cat_subplan',             'fuente',                  'VARCHAR(40) NULL'],
        // Maestro SIESA: códigos RIPS del ítem (se muestran en la Maestra Tarifaria)
        ['ref_siesa_item',          'forma_farmaceutica_rips', 'VARCHAR(30) NULL'],
        ['ref_siesa_item',          'concentracion_rips',      'VARCHAR(30) NULL'],
        ['ref_siesa_item',          'unidad_medida_rips',      'VARCHAR(30) NULL'],
        // Correcciones SIESA (2026-10-19): ID MUTUAL en el código genérico auxiliar y datos del ítem para CREACION_ITEM
        ['ref_siesa_item',          'codigo_generico_auxiliar', 'VARCHAR(40) NULL'],
        ['ref_siesa_item',          'unidad_inventario',       'VARCHAR(20) NULL'],
        ['ref_siesa_item',          'unidad_presentacion',     'VARCHAR(20) NULL'],
        ['ref_siesa_item',          'codigo_atc',              'VARCHAR(60) NULL'], // ATC en la Maestra Tarifaria (2026-10-19)
        // Versiones históricas (2026-10-19): nombre de la versión y fecha desde la que rige (fecha de ejecución)
        ['ref_carga',               'nombre_version',          'VARCHAR(150) NULL'],
        ['ref_carga',               'vigente_desde',           'DATE NULL'],
        ['ref_carga',               'vigente_hasta',           'DATE NULL'], // fecha final digitada (si no, la da la versión siguiente)
        // Correcciones MEDICAR (2026-10-19): datos de la Maestra MEDICAR que se comparan y el subplán de cada corrección
        ['ref_medicar',             'precio_um_iva',           'VARCHAR(30) NULL'],
        ['ref_medicar',             'iva_pct',                 'VARCHAR(10) NULL'],
        ['ref_medicar',             'codigo_md',               'VARCHAR(40) NULL'],
        ['ref_medicar',             'registro_invima',         'VARCHAR(60) NULL'],
        ['ref_medicar',             'tipo_medicamento',        'VARCHAR(40) NULL'],
        ['cor_inconsistencia',      'subplan',                 'VARCHAR(60) NULL'],
    ];
}

function columna_existe(PDO $pdo, string $tabla, string $columna): bool {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $st->execute([$tabla, $columna]);
        return (int) $st->fetchColumn() > 0;
    }
    foreach ($pdo->query("PRAGMA table_info($tabla)") as $c) if ($c['name'] === $columna) return true;
    return false;
}

/** CREATE TABLE IF NOT EXISTS de una tabla del esquema (MySQL o SQLite). */
function crear_tabla(PDO $pdo, string $tabla): void {
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $pk = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $opciones = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS $tabla (" . str_replace('{PK}', $pk, esquema_tablas()[$tabla]) . ")$opciones");
}

/** Crea tablas, columnas e índices que falten. Seguro de re-ejecutar (no borra nada). */
function migrar(PDO $pdo): array {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $log = [];

    // nt_producto referencia a prod_producto, que se define después: MySQL exige desactivar
    // la verificación de llaves foráneas mientras se crean las tablas (SQLite no la necesita).
    if ($driver === 'mysql') $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (array_keys(esquema_tablas()) as $tabla) {
        crear_tabla($pdo, $tabla);
        $log[] = "tabla $tabla";
    }
    if ($driver === 'mysql') $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    foreach (esquema_columnas_nuevas() as [$tabla, $col, $def]) {
        if (!columna_existe($pdo, $tabla, $col)) {
            $pdo->exec("ALTER TABLE $tabla ADD COLUMN $col $def");
            $log[] = "columna $tabla.$col";
        }
    }
    foreach (esquema_indices() as [$nombre, $tabla, $cols]) {
        if ($driver === 'mysql') {
            $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
            $st->execute([$tabla, $nombre]);
            if ((int) $st->fetchColumn() === 0) $pdo->exec("CREATE INDEX $nombre ON $tabla ($cols)");
        } else {
            $pdo->exec("CREATE INDEX IF NOT EXISTS $nombre ON $tabla ($cols)");
        }
        $log[] = "índice $nombre";
    }
    return $log;
}
