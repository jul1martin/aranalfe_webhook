<?php

try {
     include_once __DIR__ . '/index.php';

     // Crear directorios si no existen
     if (!file_exists('logs/development_cron')) {
          mkdir('logs/development_cron', 0755, true);
     }
     if (!file_exists('logs/development_cron_fails')) {
          mkdir('logs/development_cron_fails', 0755, true);
     }

     // Guardar input en archivo del día
     $day = date('d');
     $inputLogFile = "logs/development_cron/day_{$day}.log";
     file_put_contents($inputLogFile, date('Y-m-d H:i:s') . " - Input: Se ejecuta cron de propiedades" . PHP_EOL, FILE_APPEND);

     $offset = 0;
     $limit = 20;
     $sheetData = [];
     $keepSearching = true;

     $toSheet = [];

     $oldLastId = getLastIdChecked();
     $lastId = $oldLastId;
     
     // Limito a 3 ejecuciones a la api de Tokko
     while($keepSearching && $offset < 61) {
          $urlParams = 'order_by=id&id__gt=' . $oldLastId . '&limit=' . $limit . '&offset=' . $offset;

          ["objects" => $developments] = getTokko('development', $urlParams);
       
       	if(empty($developments)) {
               $keepSearching = false;
               continue;
          }

          echo "Desarrollos encontrados en el get con offset {$offset}: " . count($developments);

          foreach($developments as $development) {
               $id = $development['id'];
            
               $sheetData = getDevelopment($development);
               $toSheet[] = array_values($sheetData['sheet']);
          
               echo "Preparando datos de " . $id . " para enviar a Google Sheets\n";

               if($id > $lastId) {
                    $lastId = $id;
               }
          }

          $offset += $limit;
     }

     if (!empty($toSheet)) {
          appendToGoogleSheet($toSheet, 'Desarrollos');
     }

     setLastIdChecked($lastId);

     echo "Ejecutado correctamente";
} catch (Exception $e) {
     // Loguear error en archivo del día
     echo "Error: " . $e->getMessage();

     $day = date('d');
     $errorLogFile = "logs/development_cron_fails/day_{$day}.log";
     file_put_contents($errorLogFile, date('Y-m-d H:i:s') . " - Error: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
}

function getDevelopment($data) {
     $types = [
          1 => 'Edificio de oficinas',
          2 => 'Edificio',
          3 => 'Country', 
          4 => 'Barrio Privado',
          5 => 'Náutico',
          6 => 'Rural',
          7 => 'Edificio de Cocheras',
          8 => 'Condominio Industrial',
          9 => 'Centro Logístico',
          10 => 'Condominio',
          11 => 'Otro',
          12 => 'Comercial',
          13 => 'Hotel',
          14 => 'Barrio Abierto'
     ];

     $id = $data['id'];
     
     $body = [
          'created_at' => date('m/d/Y H:i:00', strtotime($data['deleted_at'])),
          'tokko_id' => urlForExcel("https://www.tokkobroker.com/development/{$id}", $id),
          'name' => $data['name'],
          'web_url' => urlForExcel("https://aranalfe.com/emprendimiento/?id={$id}"),
          'type' => isset($data['type']) ? ($types[$data['type']['id']] ?? $data['type']['name']) : '',
          'address' => $data['address'],
          'location_name' => $data['location']['name'] ?? ''
     ];

     return [
          "sheet" => $body,
          "page" => "Desarrollos"
     ];
}

function getLastIdChecked() {
     $file = 'last_development_id';

     if(!file_exists($file)) {
          file_put_contents($file, '0');
     }

     return file_get_contents($file);
}

function setLastIdChecked($id) {
     $file = 'last_development_id';
     file_put_contents($file, $id);
}
