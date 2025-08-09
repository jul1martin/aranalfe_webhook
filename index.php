<?php

require __DIR__ . '/vendor/autoload.php';

// Cargar variables de entorno
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2) . '/secret_tokko_webhook');

// local
// $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);

$dotenv->load();

function getTokko($resource, $urlParams = null) {
     $apiKey = $_ENV['TOKKO_API_KEY'];

     $url = "https://www.tokkobroker.com/api/v1/{$resource}?key={$apiKey}" . ($urlParams ? '&' . $urlParams : '');
     
     $context = stream_context_create([
          'http' => [
               'ignore_errors' => true
          ]
     ]);

     $attempts = 0;
     $maxAttempts = 3;
     
     do {
          $response = @file_get_contents($url, false, $context);
          $attempts++;

          if ($response !== false) {
               if (strpos($http_response_header[0], '404') !== false) {
                    return null;
               }

               return json_decode($response, true);
          }

          if ($attempts < $maxAttempts) {
               sleep(1); // Esperar 1 segundo antes de reintentar
          }

     } while ($attempts < $maxAttempts);

     throw new Exception("No se pudo obtener respuesta después de {$maxAttempts} intentos");
}

function urlForExcel($url, $text = 'VER') {
     return "=HYPERLINK(\"{$url}\", \"{$text}\")";
}

function appendToGoogleSheet($sheetData, $page) {
     try {
          $spreadsheetId = $_ENV['GOOGLE_SHEETS_ID'];

          // Configurar Google Client
          $client = new Google_Client();
          $client->setAuthConfig(dirname(__DIR__, 2) . '/secret_tokko_webhook/credenciales.json');
          
          // LOCAL
          // $client->setAuthConfig(__DIR__ . '/credenciales.json');

          $client->addScope([
               Google_Service_Sheets::SPREADSHEETS,
               Google_Service_Drive::DRIVE
          ]);

          $service = new Google_Service_Sheets($client);
          $range = "{$page}!A2";

          $body = new Google_Service_Sheets_ValueRange([
               'values' => $sheetData
          ]);

          $params = ['valueInputOption' => 'USER_ENTERED'];
          $service->spreadsheets_values->append($spreadsheetId, $range, $body, $params);

          echo "Se enviaron " . count($sheetData) . " datos a Google Sheets\n";

          return true;
     } catch (Exception $e) {
          throw new Exception("Error al enviar datos a Google Sheets: " . $e->getMessage());
     }
}