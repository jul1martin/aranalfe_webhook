<?php

try {
     // Crear directorios si no existen
     if (!file_exists('logs/contact_cron')) {
          mkdir('logs/contact_cron', 0755, true);
     }
     if (!file_exists('logs/contact_cron_fails')) {
          mkdir('logs/contact_cron_fails', 0755, true);
     }

     include_once __DIR__ . '/index.php';

     // Guardar input en archivo del día
     $day = date('d');
     $inputLogFile = "logs/contact_cron/day_{$day}.log";
     file_put_contents($inputLogFile, date('Y-m-d H:i:s') . " - Input: Se ejecuta cron de contactos" . PHP_EOL, FILE_APPEND);

     $offset = 0;
     $limit = 20;
     $sheetData = [];
     $keepSearching = true;

     $toSheet = [];
     
     while($keepSearching && $offset < 101) {
          $urlParams = 'limit=' . $limit . '&offset=' . $offset . '&format=json&key=fad0d191d200804e836be0b26626ac919fa37e8a&created_at__gte=' . date('Y-m-d 00:00:00', strtotime('-1 day')) . '&created_at__lte=' . date('Y-m-d 23:59:59', strtotime('-1 day'));

          ["objects" => $contacts] = getTokko('contact', $apiKey, $urlParams);

       	  echo "Propiedades encontradas en el get con offset {$offset}: " . count($contacts) . PHP_EOL;

          if(empty($contacts)) {
               $keepSearching = false;
               continue;
          }

          foreach($contacts as $contact) {
               $createdAt = $contact['created_at'];

               $sheetData = getContact($contact);
               $toSheet[] = array_values($sheetData['sheet']);
          
               echo 'Preparando datos de ' . $contact['id'] . ' para enviar a Google Sheets ' . PHP_EOL;
          }

          $offset += $limit;
     }

     echo "\n Cantidad de elementos que van a sheets: " . count($toSheet) . " \n";
  
  	 if (!empty($toSheet)) {
          $status = appendToGoogleSheet($toSheet, 'Contactos');
     		
          echo "Estado del envio: " ($status ? "Enviado" : "Error al enviar");
     }

     echo "Ejecutado correctamente";
} catch (Exception $e) {
     // Loguear error en archivo del día
     echo "Error: " . $e->getMessage();

     $day = date('d');
     $errorLogFile = "logs/contact_cron_fails/day_{$day}.log";
     file_put_contents($errorLogFile, date('Y-m-d H:i:s') . " - Error: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
}

function getContact($data) {
     $body = [];

     if(!$data) return $body;

     $tags = [];
     if (isset($data['tags']) && is_array($data['tags'])) {
          foreach ($data['tags'] as $tag) {
               $tags[] = $tag['name'];
          }
     }
     $tags_string = implode(", ", $tags);

     $body = [
          'created_at' => date('d/m/Y H:i', strtotime($data['created_at'])),
          'tokko_id' => urlForExcel("https://www.tokkobroker.com/contact/{$data['id']}", $data['id']),
          'name' => $data['name'],
          'email' => $data['email'] ?? $data['other_email'] ?? '',
          'cellphone' => $data['cellphone'] ?? $data['other_phone'] ?? '',
          'lead_status' => $data['lead_status'],
          'agent_name' => $data['agent'] ? $data['agent']['name'] : '',
          'tags' => $tags_string
     ];

     return [
          "sheet" => $body,
          "page" => "Contactos"
     ];
}