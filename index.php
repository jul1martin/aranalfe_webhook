<?php
require __DIR__ . '/vendor/autoload.php';
/*
PROPIEDADES

ver si pinta una fotovich
created_at
tokko id
public_url
type.name
 $property_types = [
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
Operaciones string
valor venta 
valor alquiler
development -> Si tiene dev.name - dev.id
address
total_surface
location.name

CONTACTOS
created_at
tokko_id
name
email || other_email
cellphone || other_phone
lead_status
agent.name
tags for each tag.name


DEVELOPMENT

id
name
web_url
type
$development_types = [
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


*/
try {
     // Cargar variables de entorno
     $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2) . '/secret_tokko_webhook');
     $dotenv->load();

     $input = file_get_contents('php://input');

     // Crear directorios si no existen
     if (!file_exists('log_inputs')) {
          mkdir('log_inputs', 0755, true);
     }
     if (!file_exists('log_fails')) {
          mkdir('log_fails', 0755, true);
     }

     // Guardar input en archivo del día
     $day = date('d');
     $inputLogFile = "log_inputs/day_{$day}.log";
     file_put_contents($inputLogFile, date('Y-m-d H:i:s') . " - Input: " . $input . PHP_EOL, FILE_APPEND);

     if ($input === false) {
         throw new Exception('No se pudo leer la entrada PHP');
     }
     
     $data = json_decode($input, true);

     if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
         throw new Exception('Error al decodificar JSON: ' . json_last_error_msg());
     }
     
     if (empty($data)) {
         throw new Exception('Los datos están vacíos');
     }
     
     $apiKey = $_ENV['TOKKO_API_KEY'];

     // Resources
     // https://www.tokkobroker.com/api/v1/property/7174910?format=json&key=fad0d191d200804e836be0b26626ac919fa37e8a
     // https://www.tokkobroker.com/api/v1/development/62216?format=json&key=fad0d191d200804e836be0b26626ac919fa37e8a
     // https://www.tokkobroker.com/api/v1/contact/52918638?format=json&key=fad0d191d200804e836be0b26626ac919fa37e8a
     

     // Forma webhook
     /*
     {
          "type": "property/development/contact",
          "id": ID,
     }
     */

     $sheetData = [];

     switch ($data['type']) {
          case 'property':
               $sheetData = getProperty($data['id'], $apiKey);
               break;
          case 'development':
               $sheetData = getDevelopment($data['id'], $apiKey);
               break;
          case 'contact':
               $sheetData = getContact($data['id'], $apiKey);
               break;
          default:
               throw new Exception('Tipo de recurso no válido');
     }

     if(empty($sheetData)) {
          throw new Exception('No se encontraron datos');
     }

     $values = [array_values($sheetData['sheet'])];

     // Configurar Google Client
     $client = new Google_Client();
     $client->setAuthConfig(dirname(__DIR__, 2) . '/secret_tokko_webhook/credenciales.json');
     $client->addScope([
          Google_Service_Sheets::SPREADSHEETS,
          Google_Service_Drive::DRIVE
     ]);

     $service = new Google_Service_Sheets($client);

     $spreadsheetId = $_ENV['GOOGLE_SHEETS_ID'];
     $range = $sheetData['page'] . '!A2';

     $body = new Google_Service_Sheets_ValueRange([
          'values' => $values
     ]);

     $params = ['valueInputOption' => 'USER_ENTERED'];
     $service->spreadsheets_values->append($spreadsheetId, $range, $body, $params);

     echo json_encode(['status' => 'ok']);
} catch (Exception $e) {
     // Loguear error en archivo del día
     echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);

     $day = date('d');
     $errorLogFile = "log_fails/day_{$day}.log";
     file_put_contents($errorLogFile, date('Y-m-d H:i:s') . " - Error: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
     
}

function getProperty($id, $apiKey) {
     $data = getTokko('property', $id, $apiKey);
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

function getDevelopment($id, $apiKey) {
     $data = getTokko('development', $id, $apiKey);
     $body = [];

     if(!$data) return $body;

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

function getContact($id, $apiKey) {
     $data = getTokko('contact', $id, $apiKey);
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

function getTokko($resource, $id, $apiKey) {
     $url = "https://www.tokkobroker.com/api/v1/{$resource}/{$id}?format=json&key={$apiKey}";
     
     $context = stream_context_create([
          'http' => [
               'ignore_errors' => true
          ]
     ]);

     $response = file_get_contents($url, false, $context);
     
     if (strpos($http_response_header[0], '404') !== false) {
          return null;
     }
     
     return json_decode($response, true);
}

function urlForExcel($url, $text = 'VER') {
     return "=HYPERLINK(\"{$url}\", \"{$text}\")";
}