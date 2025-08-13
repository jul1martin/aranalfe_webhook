<?php

try {
     include_once __DIR__ . '/index.php';

     // Crear directorios si no existen
     if (!file_exists('logs/prop_cron')) {
          mkdir('logs/prop_cron', 0755, true);
     }
     if (!file_exists('logs/prop_cron_fails')) {
          mkdir('logs/prop_cron_fails', 0755, true);
     }

     // Guardar input en archivo del día
     $day = date('d');
     $inputLogFile = "logs/prop_cron/day_{$day}.log";
     file_put_contents($inputLogFile, date('Y-m-d H:i:s') . " - Input: Se ejecuta cron de propiedades" . PHP_EOL, FILE_APPEND);

     $offset = 0;
     $limit = 20;
     $sheetData = [];
     $keepSearching = true;

     $toSheet = [];
     
     $oldLastId = getLastIdChecked();
     $lastId = $oldLastId;

     // Limito a 10 ejecuciones a la api de Tokko
     while($keepSearching && $offset < 101) {
          $urlParams = 'order_by=deleted_at&id__gt=' . $oldLastId . '&limit=' . $limit . '&offset=' . $offset;

          ["objects" => $properties] = getTokko('property', $urlParams);

          if(empty($properties)) {
               $keepSearching = false;
               continue;
          }

          echo "Propiedades encontradas en el get con offset {$offset}: " . count($properties);

          foreach($properties as $property) {
               $createdAt = $property['created_at'];
               $id = $property['id'];

               $sheetData = getProperty($property);

               if(!empty($sheetData['sheet'])) {
                    $toSheet[] = array_values($sheetData['sheet']);
               
                    echo "Preparando datos de " . $property['id'] . " para enviar a Google Sheets\n";

                    if($id > $lastId) {
                         $lastId = $id;
                    }
               } else {
                    echo "No se encontraron datos para la propiedad " . $property['id'] . "\n";
               }
          }

          $offset += $limit;
     }

     if (!empty($toSheet)) {
          appendToGoogleSheet($toSheet, 'Propiedades');
     }

     setLastIdChecked($lastId);

     echo "Ejecutado correctamente";
} catch (Exception $e) {
     // Loguear error en archivo del día
     echo "Error: " . $e->getMessage();

     $day = date('d');
     $errorLogFile = "logs/prop_cron_fails/day_{$day}.log";
     file_put_contents($errorLogFile, date('Y-m-d H:i:s') . " - Error: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
}

function getProperty($data) {
     $types = [
          1 => 'Terreno',
          2 => 'Departamento', 
          3 => 'Casa',
          5 => 'Oficina',
          7 => 'Local',
          8 => 'Edificio Comercial',
          10 => 'Cochera',
          11 => 'Hotel',
          13 => 'PH',
          14 => 'Depósito',
          24 => 'Galpón'
     ];

     $operations = $data['operations'] ?? [];

     $operationString = '';
     $salePrice = 0;
     $rentalPrice = 0;

     foreach($operations as $operation) {
          $translate = [
               "Rent" => "Alquiler",
               "Sale" => "Venta",
               // alq temporario?
          ];

          $operationString .= ($operationString ? ' - ' : '') . $translate[$operation['operation_type']];

          $price = $operation['prices'][0]['price'] ?? 0;
          
          if($operation['operation_type'] == 'Sale') {
               $salePrice = $price;
          } else if($operation['operation_type'] == 'Rent') {
               $rentalPrice = $price;
          }
     }

     $id = $data['id'];

     $ownerNames = '';

     if(!empty($data['internal_data']['property_owners'])) {
          foreach($data['internal_data']['property_owners'] as $key => $propertyOwner) {
               $ownerNames .= $propertyOwner['name'];

               if($key < count($data['internal_data']['property_owners']) - 1) {
                    $ownerNames .= ', ';
               }
          }
     }
     
     $body = [
          'created_at' => date('m/d/Y H:i:00', strtotime($data['created_at'])),
          'tokko_id' => urlForExcel("https://www.tokkobroker.com/property/{$id}", $id),
          'public_url' => urlForExcel("https://aranalfe.com/propiedad/?id={$id}"),
          'type_name' => isset($data['type']['id']) ? ($types[$data['type']['id']] ?? $data['type']['name']) : '',
          'operaciones' => $operationString,
          'valor_venta' => $salePrice,
          'valor_alquiler' => $rentalPrice,
          'development' => isset($data['development']) ? $data['development']['name'] . ' (' . $data['development']['id'] . ')' : '',
          'address' => $data['address'],
          'location_name' => $data['location']['name'] ?? '',
          'owner_names' => $ownerNames,
          'total_surface' => $data['total_surface']
     ];

     return [
          "sheet" => $body,
          "page" => "Propiedades"
     ];
}

function getLastIdChecked() {
     $file = 'last_prop_id';

     if(!file_exists($file)) {
          file_put_contents($file, '0');
     }
     return file_get_contents($file);
}

function setLastIdChecked($id) {   
     file_put_contents('last_prop_id', $id);
}