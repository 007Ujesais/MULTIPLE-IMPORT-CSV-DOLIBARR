<?php
// 1. INITIALISATION DOLIBARR
$dolibarr_nocsrfcheck = 1;

require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formother.class.php';
require_once DOL_DOCUMENT_ROOT.'/imports/class/import.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/modules/import/modules_import.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/images.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/import.lib.php';

// Chargement des traductions
$langs->loadLangs(array("csvimport@csvimport", "other"));



// Configuration
$max_file_size = getDolGlobalInt('MAIN_UPLOAD_DOC', 10) * 1024 * 1024; // 10MB
$upload_dir = $conf->csvimport->dir_output.'/temp';
$action = GETPOST('action', 'alpha');

// Initialisation objet Import
$objimport = new Import($db);
$objimport->load_arrays($user);





// 2. GESTION DES ACTIONS
if ($action == 'process_queue') {
    include_once __DIR__ . '/lib/csvimport.lib.php';

    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    
    $files = $_SESSION['csvimport_queue'] ?? array();

    foreach ($files as $key => $file) {
        process_csv_file($file, $db);
    }
    
    // Nettoyage
    // unset($_SESSION['csvimport_queue']);
    // header("Location: ".$_SERVER['PHP_SELF']);
    // exit;
}






elseif ($action == 'add_to_queue' && !empty($_FILES['csvfile']['tmp_name'])) {
    // Ajout d'un fichier à la liste d'attente
    if (!is_dir($upload_dir)) dol_mkdir($upload_dir);
    
    $filename = dol_sanitizeFileName($_FILES['csvfile']['name']);
    $tmp_name = $upload_dir.'/'.md5(uniqid()).'.csv';
    $data_type = GETPOST('data_type', 'aZ09');
    
    // Vérification du type
    $valid_type = in_array($data_type, $objimport->array_import_code);
    
    if ($valid_type && move_uploaded_file($_FILES['csvfile']['tmp_name'], $tmp_name)) {
        // Récupération du libellé du module
        $key = array_search($data_type, $objimport->array_import_code);
        $module_name = $objimport->array_import_module[$key]['module']->getName();
        
        $_SESSION['csvimport_queue'][] = array(
            'name' => $filename,
            'size' => $_FILES['csvfile']['size'],
            'tmp_path' => $tmp_name,
            'data_type' => $data_type,
            'data_type_label' => $module_name
        );
    }
}
elseif ($action == 'remove_from_queue') {
    // Suppression d'un fichier de la liste
    $index = GETPOST('index', 'int');
    if (isset($_SESSION['csvimport_queue'][$index])) {
        if (file_exists($_SESSION['csvimport_queue'][$index]['tmp_path'])) {
            unlink($_SESSION['csvimport_queue'][$index]['tmp_path']);
        }
        unset($_SESSION['csvimport_queue'][$index]);
    }
}

// 3. AFFICHAGE HTML
llxHeader('', $langs->trans("CSVImportModule"));
print load_fiche_titre('DINAMIC CSV IMPORT by Nantah');

// CSS
print '<style>
.queue-table { width:100%; margin-top:20px; border-collapse:collapse; }
.queue-table th { text-align:left; padding:10px; background:#f0f0f0; font-weight:600; }
.queue-table td { padding:10px; border-bottom:1px solid #e0e0e0; vertical-align:top; }
.remove-btn { color:#c00; cursor:pointer; font-weight:bold; font-size:1.2em; }
.file-info { margin:15px 0; padding:10px; background:#f8f8f8; border-radius:4px; }
.import-panel { margin-top:20px; padding:20px; border:1px solid #ddd; border-radius:4px; }
</style>';

// JavaScript
print '<script>
function validateAddToQueue() {
    var file = document.getElementById("csvfile").files[0];
    var dataType = document.getElementById("data_type").value;
    
    if (!file) {
        alert("'.dol_escape_js($langs->trans("SelectFileFirst")).'");
        return false;
    }
    if (!dataType) {
        alert("'.dol_escape_js($langs->trans("SelectDataTypeFirst")).'");
        return false;
    }
    
    // Vérification extension
    if (!file.name.match(/\.(csv|txt)$/i)) {
        alert("'.dol_escape_js($langs->trans("ErrorFileMustBeCSV")).'");
        return false;
    }
    
    return true;
}

function removeFromQueue(index) {
    if (confirm("'.dol_escape_js($langs->trans("ConfirmRemoveFile")).'")) {
        document.getElementById("remove_index").value = index;
        document.getElementById("remove_form").submit();
    }
}
</script>';

// Formulaire principal
print '<div class="import-panel">';

// Formulaire d'ajout
print '<form id="add_form" method="POST" enctype="multipart/form-data" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" onsubmit="return validateAddToQueue()">
    <input type="hidden" name="token" value="'.newToken().'">
    <input type="hidden" name="action" value="add_to_queue">
    
    <table class="noborder" width="100%">
        <tr>
            <td width="20%">'.$langs->trans("SelectCSVFile").'</td>
            <td><input type="file" name="csvfile" id="csvfile" accept=".csv,.txt" class="flat"></td>
        </tr>
        <tr>
            <td>'.$langs->trans("DataType").'</td>
            <td>';

// Menu déroulant des types d'import
print '<select name="data_type" id="data_type" class="flat" required>
    <option value="">-- '.$langs->trans("SelectDataType").' --</option>';

foreach ($objimport->array_import_code as $key => $code) {
    if ($objimport->array_import_perms[$key]) { // Vérification des permissions
        $module = $objimport->array_import_module[$key]['module'];
        print '<option value="'.$code.'">'.$module->getName().'</option>';
    }
}

print '</select>';

print '</td>
        </tr>
        <tr>
            <td colspan="2" class="center">
                <input type="submit" class="button" value="'.$langs->trans("AddToQueue").'">
            </td>
        </tr>
    </table>
</form>';

// Liste d'attente
if (!empty($_SESSION['csvimport_queue'])) {
    print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">
        <input type="hidden" name="token" value="'.newToken().'">
        <input type="hidden" name="action" value="process_queue">
        
        <table class="queue-table">
            <tr>
                <th width="40%">'.$langs->trans("FileName").'</th>
                <th width="20%">'.$langs->trans("FileSize").'</th>
                <th width="30%">'.$langs->trans("DataType").'</th>
                <th width="10%"></th>
            </tr>';
    
    foreach ($_SESSION['csvimport_queue'] as $key => $file) {
        print '<tr>
            <td>'.dol_escape_htmltag($file['name']).'</td>
            <td>'.dol_print_size($file['size']).'</td>
            <td>'.dol_escape_htmltag($file['data_type_label']).'</td>
            <td class="center"><span class="remove-btn" onclick="removeFromQueue('.$key.')">&times;</span></td>
        </tr>';
    }
    
    print '</table>
        <div class="center" style="margin-top:20px;">
            <input type="submit" class="button button-save" value="'.$langs->trans("ProcessQueue").'">
        </div>
    </form>';
}

print '</div>'; // Fin import-panel

// Formulaire caché pour suppression
print '<form id="remove_form" method="POST" style="display:none;">
    <input type="hidden" name="token" value="'.newToken().'">
    <input type="hidden" name="action" value="remove_from_queue">
    <input type="hidden" name="index" id="remove_index" value="">
</form>';

llxFooter();