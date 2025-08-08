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
     
     while($keepSearching && $offset < 101) {
          $urlParams = 'lang=es_ar&order_by=created_at&format=json&limit=' . $limit . '&offset=' . $offset . '&data=%7B%22current_localization_id%22%3A0%2C%22current_localization_type%22%3A%22country%22%2C%22price_from%22%3A1%2C%22price_to%22%3A999999999%2C%22operation_types%22%3A%5B1%2C2%5D%2C%22property_types%22%3A%5B1%2C2%2C3%2C4%2C5%2C6%2C7%2C8%2C9%2C10%2C11%2C12%2C13%2C14%2C15%2C16%2C17%2C18%2C19%2C20%2C21%2C22%2C23%2C24%2C25%2C26%2C27%2C28%5D%2C%22currency%22%3A%22USD%22%2C%22filters%22%3A%5B%5B%22total_surface%22%2C%22%3E%22%2C1%5D%2C%5B%22total_surface%22%2C%22%3C%22%2C999999999%5D%5D%2C%22with_tags%22%3A%5B%5D%7D&order=desc';

          ["objects" => $properties] = getTokko('property/search', $apiKey, $urlParams);
       
          echo "Propiedades encontradas en el get con offset {$offset}: " . count($properties);
          
       	  if(empty($properties)) {
               $keepSearching = false;
               continue;
          }

          foreach($properties as $property) {
               $createdAt = $property['created_at'];
            
               if($createdAt > date('Y-m-d 00:00:00', strtotime('-1 day'))) {
                    $sheetData = getProperty($property['id']);
                    $toSheet[] = array_values($sheetData['sheet']);
               
                    echo "Preparando datos de " . $property['id'] . " para enviar a Google Sheets\n";
               } else {
                    $keepSearching = false;
                    break;
               }
          }

          $offset += $limit;
     }

     if (!empty($toSheet)) {
          appendToGoogleSheet($toSheet, 'Propiedades');
     }

     echo "Ejecutado correctamente";
} catch (Exception $e) {
     // Loguear error en archivo del día
     echo "Error: " . $e->getMessage();

     $day = date('d');
     $errorLogFile = "logs/prop_cron_fails/day_{$day}.log";
     file_put_contents($errorLogFile, date('Y-m-d H:i:s') . " - Error: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
}

function getProperty($id) {
     $data = getTokko('property', $id);
     $body = [];

     if(!$data) return $body;

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
               "Sell" => "Venta",
               // alq temporario?
          ];

          $operationString .= ($operationString ? ' - ' : '') . $translate[$operation['operation_type']];

          $price = $operation['prices'][0]['price'] ?? 0;
          
          if($operation['operation_type'] == 'Sell') {
               $salePrice = $price;
          } else if($operation['operation_type'] == 'Rent') {
               $rentalPrice = $price;
          }
     }

     $id = $data['id'];

     $body = [
          'created_at' => $data['created_at'],
          'tokko_id' => urlForExcel("https://www.tokkobroker.com/property/{$id}", $id),
          'public_url' => urlForExcel("https://aranalfe.com/propiedad/?id={$id}"),
          'type_name' => isset($data['type']['id']) ? ($types[$data['type']['id']] ?? $data['type']['name']) : '',
          'operaciones' => $operationString,
          'valor_venta' => $salePrice,
          'valor_alquiler' => $rentalPrice,
          'development' => isset($data['development']) ? $data['development']['name'] . ' (' . $data['development']['id'] . ')' : '',
          'address' => $data['address'],
          'location_name' => $data['location']['name'] ?? '',
          'total_surface' => $data['total_surface']
     ];

     return [
          "sheet" => $body,
          "page" => "Propiedades"
     ];
}

