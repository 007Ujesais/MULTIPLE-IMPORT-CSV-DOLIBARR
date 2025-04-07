<?php

function process_csv_file($file, $db) {
    global $langs;
	
    $file_path = $file['tmp_path'];
    
    if (!file_exists($file_path)) {
        setEventMessages($langs->trans("ErrorFileNotFound", $file_path), null, 'errors');
        return false;
    }
    
    $import_code = $file['data_type'];
    $module_class_name = get_module_class_name($import_code);
    
    if (empty($module_class_name)) {
        setEventMessages($langs->trans("ErrorCannotDetermineImportType"), null, 'errors');
        return false;
    }


    // Chargement des fichiers nécessaires
    if (!get_required_files_class($module_class_name)) {
        setEventMessages($langs->trans("ErrorCannotLoadClassFiles", $module_class_name), null, 'errors');
        return false;
    }

    // Création de l'objet dynamique
    $object = get_class_object($module_class_name, $db);
    if (!$object) {
        setEventMessages($langs->trans("ErrorCannotCreateObject", $module_class_name), null, 'errors');
        return false;
    }

    $handle = fopen($file_path, 'r');
    if (!$handle) {
        setEventMessages($langs->trans("ErrorCannotOpenFile", $file_path), null, 'errors');
        return false;
    }

    // Lecture de l'en-tête pour le mapping
    $header = fgetcsv($handle, 0, ',');
    $column_mapping = create_column_mapping($header, $object);

    $db->begin();
    
    // echo "<pre>";
    // print_r($object);
    // echo "</pre>";

    try {
        while (($data = fgetcsv($handle, 0, ',')) !== false) {
            // Mapping des données selon l'en-tête
            $mapped_data = map_data_to_object($data, $header, $column_mapping);


			if (fill_object_with_data_in_csv($object, $mapped_data)) {
				create_object_fill($object);
			}
        }
        
        $db->commit();
        
    } catch (Exception $e) {
        $db->rollback();
        setEventMessages($langs->trans("ErrorDuringImport") . ": " . $e->getMessage(), null, 'errors');
        return false;
    } finally {
        fclose($handle);
    }
}

/**
 * Crée un mapping entre les colonnes CSV et les propriétés de l'objet 
 */
function create_column_mapping($header, $object) {
    $mapping = [];
    $property_aliases = get_property_aliases(get_class($object));
    
    foreach ($header as $column) {
        $normalized_column = strtolower(trim($column));
        
        // Vérifie si la colonne correspond directement à une propriété
        if (property_exists($object, $normalized_column)) {
            $mapping[$column] = $normalized_column;
        }
        
        // Vérifie les alias connus
        foreach ($property_aliases as $property => $aliases) {
            if (in_array($normalized_column, $aliases)) {
                $mapping[$column] = $property;
            }
        }
    }
    
    return $mapping;
}

/**
 * Retourne les alias possibles pour les propriétés d'une classe
 */
function get_property_aliases($class_name) {
    global $user, $db;

    $aliases = [
		'Product' => [
            'ref' => ['ref_produit', 'reference', 'product_ref', 'code'],
            'label' => ['libelle', 'designation', 'description', 'name'],
            'categorie' => ['categorie', 'categori', 'category'],
            'note' => ['nature', 'type', 'comment'],
            'price' => ['prix vente', 'prix_vente', 'unit_price', 'cost'],
            'tva_tx' => ['TVA', 'vat', 'tva', 'vat_rate'],
            'weight' => ['poids', 'weight', 'mass'],
            'weight_units' => ['unite', 'unit', 'weight_unit']
        ],
    ];
    
    return $aliases[$class_name] ?? [];
}

/**
 * Map les données CSV aux propriétés de l'objet
 */
function map_data_to_object($data, $header, $column_mapping) {
    $mapped_data = [];
    
    foreach ($header as $index => $column) {
        if (isset($column_mapping[$column]) && isset($data[$index])) {
            $property = $column_mapping[$column];
            $mapped_data[$property] = trim($data[$index]);
        }
    }
    
    return $mapped_data;
}

function get_module_class_name($import_code) {

	$key = get_key_import_code($import_code);

    $module = get_module($key);
    $module_class_name = $module->name;

    return $module_class_name;
}

function get_key_import_code($import_code) {
    global $user, $db;

    $module = new Import($db);
	$module->load_arrays($user);

    $key = array_search($import_code, $module->array_import_code);
	if ($key !== false) {
        return $key;
    }
    return;
}

function get_module($key) {
	global $user, $db;



    $module = new Import($db);
	$module->load_arrays($user);
    return $module->array_import_module[$key]['module'];
}

function get_required_files_class($module_class_name) {
    $standard_paths = [
        "/{$module_class_name}/class/{$module_class_name}.class.php",
        "/{$module_class_name}/core/modules/{$module_class_name}.class.php",
        "/{$module_class_name}/class/{$module_class_name}.class.php"
    ];

    foreach ($standard_paths as $path) {
        if (file_exists(DOL_DOCUMENT_ROOT.$path)) {
            require_once DOL_DOCUMENT_ROOT.$path;
            return true;
        }
    }
    return false;
}

function get_class_object($module_class_name, $db) {
    $object_class = ucfirst($module_class_name);
    if (!class_exists($object_class)) {
        return null;
    }
    return new $object_class($db);
}

function convert_value_to_type($value, $field_type) {
    $value = trim($value);
    
    switch ($field_type) {
        case 'integer':
        case 'int':
            return (int)$value;
            
        case 'double':
        case 'float':
            return (float)str_replace(',', '.', $value);
            
        case 'price':
            return price2num($value); 
            
        case 'date':
        case 'datetime':
            return dol_stringtotime($value);
            
        case 'boolean':
            return (bool)$value;
            
        default:
            return $value;
    }
}

function get_default_value($field_type) {
    $defaults = [
        'integer' => 0,
        'double' => 0.0,
        'varchar' => '',
        'text' => '',
        'date' => '',
        'boolean' => false
    ];
    
    return $defaults[$field_type] ?? null;
}

function create_object_fill($object) {
	global $user, $langs;

	$success_count = 0;
    $error_count = 0;

	$res = $object->create($user);
	if ($res > 0) {
	    $success_count++;
	
	    if (method_exists($object, 'postImportActions')) {
	        $object->postImportActions($user);
	    }
	
	    setEventMessages($langs->trans("RecordImportedSuccessfully", $object->name, $object->getNomUrl()), null, 'mesgs');
		return true;
	} else {
	    $error_count++;
	    setEventMessages($langs->trans("ErrorRecordImportFailed", $object->name, $object->error), null, 'errors');
		return false;
	}

	echo "<table>";
	echo "<tr><td>Nombre de succès</td><td>Nombre d'échecs</td></tr>";
	echo "<tr><td>" . $success_count . "</td><td>" . $error_count . "</td></tr>";
	echo "</table>";
}

function fill_object_with_data_in_csv($object, $mapped_data) {
    
	$object->initAsSpecimen();

	foreach ($object->fields as $field_name => $field_def) {
		if (array_key_exists($field_name, $mapped_data)) {
			$object->$field_name = convert_value_to_type($mapped_data[$field_name], $field_def['type']);
		}
		elseif ($field_def['notnull'] && empty($object->$field_name)) {
			$object->$field_name = get_default_value($field_def['type']);
		}
	}

	foreach ($mapped_data as $property => $value_property) {			
		if (property_exists($object, $property)) {
			$object->$property = $value_property;
		}
	}
	return 1;
}