<?php
/**
 * PUBLICACIÓN DE LA MAESTRA TARIFARIA — huella de versión y copia completa para sistemas externos.
 *
 * Los sistemas externos consultan nt_version (barata) y, solo si la huella cambió, piden nt_snapshot
 * (todas las filas calculadas por nt_calcular, una sola vez). No duplican las reglas: reutilizan este cálculo.
 *
 * Autenticación: llave de API en la cabecera X-API-Key (sin sesión ni cookies). Las llaves viven en la variable de entorno
 * CI_PUBLICACION_API_KEYS; si no está configurada, ambas acciones responden 503.
 */

/** Acciones que se autentican con llave de API y no usan sesión. */
const ACCIONES_CON_LLAVE = ['nt_version', 'nt_snapshot'];

/** Exige una llave de API válida en la cabecera X-API-Key. Compara en tiempo constante; nunca se acepta por la URL. */
function requerir_llave_publicacion(): void {
    $validas = array_filter(array_map('trim', explode(',', (string) app_config()['publicacion_llaves'])), fn($k) => strlen($k) >= 32);
    if (!$validas) throw new ErrorApi('La publicación por llave de API no está configurada.', 503);
    $dada = (string) ($_SERVER['HTTP_X_API_KEY'] ?? '');
    $ok = false;
    foreach ($validas as $k) $ok = hash_equals($k, $dada) || $ok; // recorre todas: el tiempo no revela cuál coincidió
    if (!$ok) {
        error_log('[nt_publicacion] llave de API inválida desde ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        throw new ErrorApi('Llave de API inválida.', 401);
    }
}

/** Tablas cuyo contenido completo determina el resultado de nt_calcular(). */
const NT_VERSION_TABLAS = ['nt_item', 'nt_item_plu', 'cor_aprobacion', 'cat_molecula', 'cat_subplan', 'cat_grupo_impositivo', 'proc_regla'];

/** Bases de referencia que lee nt_calcular(): se usa la carga vigente de cada una. */
const NT_VERSION_CARGAS = ['CONTRATO_EVENTO_22382', 'LISTADO_CAPITA', 'LISTADO_EVENTO_SAS', 'INVIMA', 'MAESTRO_SIESA',
                           'PRECIOS_REGULADOS', 'MEDICAMENTOS_MUTUAL', 'MAESTRA_MEDICAR'];

/**
 * Huella de las entradas del cálculo. Cambia si cambia cualquier línea de la Maestra Tarifaria, un catálogo,
 * una regla o la carga vigente de una base. Supone que el contenido de una carga no se edita después de crearla.
 * @return array{version: string, items: int, cargas: array<string, int>}
 */
function nt_version_datos(PDO $pdo): array {
    $h = hash_init('sha256');
    $items = 0;
    foreach (NT_VERSION_TABLAS as $t) {
        hash_update($h, "#$t\n");
        $n = 0;
        foreach ($pdo->query("SELECT * FROM $t ORDER BY 1") as $fila) {
            hash_update($h, json_encode($fila, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n");
            $n++;
        }
        if ($t === 'nt_item') $items = $n;
    }
    $cargas = [];
    foreach (NT_VERSION_CARGAS as $base) {
        $cargas[$base] = _carga_vigente($pdo, $base);
        hash_update($h, "$base=$cargas[$base]\n");
    }
    return ['version' => hash_final($h), 'items' => $items, 'cargas' => $cargas];
}

/** Todas las filas calculadas, con la misma forma que muestra nt_listar (una fila por línea y PLU). */
function nt_filas_publicables(PDO $pdo): array {
    $calc = nt_calcular($pdo);
    return ['filas' => nt_filas_por_plu($calc['filas']), 'bases_faltantes' => $calc['bases_faltantes']];
}
