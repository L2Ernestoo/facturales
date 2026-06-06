<?php

namespace Lc\Fel\Services\Certifiers;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;
use Lc\Fel\Contracts\CertifierInterface;
use Lc\Fel\Contracts\FelConfigRepositoryInterface;
use Lc\Fel\Data\FelDocumentData;
use Lc\Fel\Models\FelDocument;
use Lc\Fel\Services\DocumentBuilderFactory;

class GuatefacturasCertifier implements CertifierInterface
{
    public function __construct(
        private readonly DocumentBuilderFactory $builders,
        private readonly FelConfigRepositoryInterface $configRepository,
    ) {
    }

    public function certify(FelDocumentData $data, FelDocument $document): array
    {
        $xml = null;
        $soapRequest = null;

        try {
            $builder = $this->builders->make($data->documentType);
            $xml = $builder->build($data);
            $config = $this->resolveConfig($data);
            $soapRequest = $this->buildGenerateSoapEnvelope(
                $xml,
                $this->getNumericDocType($data->documentType),
                $data->branch->establishmentCode ?: ($config['establishment_code'] ?? '1'),
                $config['machine_id'] ?? '1',
                $config
            );

            $document->update([
                'status' => FelDocument::STATUS_PENDING,
                'request_xml' => $xml,
                'soap_request' => $soapRequest,
                'error_message' => null,
            ]);

            $responseBody = $this->executeSoapRequest($soapRequest, 'http://dbguatefac/Guatefac.wsdl/generaDocumento', $config);
            $parsed = $this->parseGenerateSoapResponse($responseBody);

            if (!isset($parsed['uuid'])) {
                throw new Exception($parsed['error'] ?? 'Respuesta inválida del certificador.');
            }

            $document->update([
                'fel_serie' => $parsed['serie'] ?? null,
                'fel_preimpreso' => $parsed['preimpreso'] ?? null,
                'fel_uuid' => $parsed['uuid'] ?? null,
                'response_body' => $responseBody,
                'status' => FelDocument::STATUS_CERTIFIED,
                'certified_at' => Carbon::now(config('fel.timezone', 'America/Guatemala')),
                'error_message' => null,
            ]);

            return [
                'success' => true,
                'data' => array_merge($parsed, [
                    'request_xml' => $xml,
                    'raw_response' => $responseBody,
                    'soap_request' => $soapRequest,
                ]),
            ];
        } catch (Exception $e) {
            $document->update([
                'status' => FelDocument::STATUS_ERROR,
                'request_xml' => $xml,
                'soap_request' => $soapRequest,
                'error_message' => $e->getMessage(),
            ]);

            Log::error('FEL certification failed', [
                'document_id' => $document->id,
                'source_type' => $document->source_type,
                'source_id' => $document->source_id,
                'document_type' => $data->documentType,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'debug' => [
                    'request_xml' => $xml,
                    'soap_request' => $soapRequest,
                ],
            ];
        }
    }

    public function annul(FelDocument $document, string $reason, array $context = []): array
    {
        try {
            $company = $document->company;
            if (!$company) {
                throw new Exception('El DTE no tiene empresa emisora configurada.');
            }

            $config = $this->resolveConfigFromCompany($company);
            $fecha = Carbon::now(config('fel.timezone', 'America/Guatemala'))->format('Ymd');
            $soapRequest = $this->buildAnnulSoapEnvelope(
                (string) $document->fel_serie,
                (string) $document->fel_preimpreso,
                $document->buyer_nit ?: 'CF',
                $fecha,
                $reason,
                $config
            );
            $responseBody = $this->executeSoapRequest($soapRequest, 'http://dbguatefac/Guatefac.wsdl/anulaDocumento', $config);
            $parsed = $this->parseAnnulSoapResponse($responseBody);

            if (($parsed['success'] ?? false) !== true) {
                throw new Exception($parsed['error'] ?? 'No se pudo anular el documento FEL.');
            }

            return ['success' => true, 'data' => array_merge($parsed, ['raw_response' => $responseBody])];
        } catch (Exception $e) {
            Log::error('FEL annulment failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function resolveConfig(FelDocumentData $data): array
    {
        $company = $this->configRepository->company($data->company->id);
        if (!$company) {
            throw new Exception('No se encontró empresa FEL activa.');
        }

        return $this->resolveConfigFromCompany($company) + [
            'mode' => $data->company->mode,
            'nit' => $data->company->nit,
            'establishment_code' => $data->branch->establishmentCode,
        ];
    }

    private function resolveConfigFromCompany($company): array
    {
        $credential = $this->configRepository->credentials($company);
        if (!$credential) {
            throw new Exception('No se configuraron credenciales FEL para la empresa.');
        }

        return [
            'mode' => $company->mode ?: config('fel.defaults.mode', 'test'),
            'user' => $credential->user,
            'pass' => $credential->password_plain,
            'nit' => $company->nit,
            'url_test' => $credential->url_test,
            'url_prod' => $credential->url_prod,
            'basic_user' => $credential->basic_user,
            'basic_pass' => $credential->basic_password_plain,
            'machine_id' => $credential->machine_id ?: config('fel.defaults.machine_id', '1'),
        ];
    }

    private function buildGenerateSoapEnvelope(string $xmlCData, int $documentType, string $establishment, string $machineId, array $config): string
    {
        $user = htmlspecialchars((string) ($config['user'] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $pass = htmlspecialchars((string) ($config['pass'] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $nit = htmlspecialchars((string) ($config['nit'] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $establishment = htmlspecialchars($establishment, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $machineId = htmlspecialchars($machineId, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return <<<XML
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:guat="http://dbguatefac/Guatefac.wsdl">
   <soapenv:Header/>
   <soapenv:Body>
      <guat:generaDocumento>
         <pUsuario>{$user}</pUsuario>
         <pPassword>{$pass}</pPassword>
         <pNitEmisor>{$nit}</pNitEmisor>
         <pEstablecimiento>{$establishment}</pEstablecimiento>
         <pTipoDoc>{$documentType}</pTipoDoc>
         <pIdMaquina>{$machineId}</pIdMaquina>
         <pTipoRespuesta>R</pTipoRespuesta>
         <pXml><![CDATA[{$xmlCData}]]></pXml>
      </guat:generaDocumento>
   </soapenv:Body>
</soapenv:Envelope>
XML;
    }

    private function buildAnnulSoapEnvelope(string $serie, string $preimpreso, string $nitComprador, string $fechaAnulacion, string $motivo, array $config): string
    {
        $user = htmlspecialchars((string) ($config['user'] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $pass = htmlspecialchars((string) ($config['pass'] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $nit = htmlspecialchars((string) ($config['nit'] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $serie = htmlspecialchars($serie, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $preimpreso = htmlspecialchars($preimpreso, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $nitComprador = htmlspecialchars($nitComprador, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $motivo = htmlspecialchars($motivo, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return <<<XML
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:guat="http://dbguatefac/Guatefac.wsdl">
   <soapenv:Header/>
   <soapenv:Body>
      <guat:anulaDocumento>
         <pUsuario>{$user}</pUsuario>
         <pPassword>{$pass}</pPassword>
         <pNitEmisor>{$nit}</pNitEmisor>
         <pSerie>{$serie}</pSerie>
         <pPreimpreso>{$preimpreso}</pPreimpreso>
         <pNitComprador>{$nitComprador}</pNitComprador>
         <pFechaAnulacion>{$fechaAnulacion}</pFechaAnulacion>
         <pMotivoAnulacion>{$motivo}</pMotivoAnulacion>
      </guat:anulaDocumento>
   </soapenv:Body>
</soapenv:Envelope>
XML;
    }

    private function executeSoapRequest(string $soapRequest, string $soapAction, array $config): string
    {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $this->resolveServiceUrl($config),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $soapRequest,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/soap+xml; charset=utf-8',
                "SOAPAction: {$soapAction}",
            ],
            CURLOPT_USERPWD => ($config['basic_user'] ?? '') . ':' . ($config['basic_pass'] ?? ''),
        ]);

        if (function_exists('app') && app()->isLocal()) {
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        }

        $responseBody = curl_exec($curl);
        $error = curl_error($curl);
        curl_close($curl);

        if ($error) {
            throw new Exception('cURL Error: ' . $error);
        }
        if (!is_string($responseBody) || trim($responseBody) === '') {
            throw new Exception('Respuesta vacía del certificador.');
        }

        return $responseBody;
    }

    private function parseGenerateSoapResponse(string $soapResponse): array
    {
        preg_match('/<(?:result|return)>(.*?)<\/(?:result|return)>/s', $soapResponse, $resultMatches);
        if (!isset($resultMatches[1])) {
            preg_match('/<faultstring>(.*?)<\/faultstring>/s', $soapResponse, $errorMatches);
            return ['error' => html_entity_decode($errorMatches[1] ?? 'Formato de respuesta SOAP no reconocido.')];
        }

        $decodedResult = trim(html_entity_decode($resultMatches[1]));
        $xml = @simplexml_load_string($decodedResult);
        if ($xml !== false && isset($xml->NumeroAutorizacion)) {
            return [
                'serie' => (string) ($xml->Serie ?? ''),
                'preimpreso' => (string) ($xml->Preimpreso ?? ''),
                'uuid' => (string) ($xml->NumeroAutorizacion ?? ''),
            ];
        }

        if (preg_match('/NumeroAutorizacion>\s*([^<]+)\s*</', $decodedResult, $uuidMatches) === 1) {
            preg_match('/Serie>\s*([^<]+)\s*</', $decodedResult, $serieMatches);
            preg_match('/Preimpreso>\s*([^<]+)\s*</', $decodedResult, $preimpresoMatches);

            return [
                'serie' => $serieMatches[1] ?? '',
                'preimpreso' => $preimpresoMatches[1] ?? '',
                'uuid' => $uuidMatches[1],
            ];
        }

        return ['error' => $decodedResult];
    }

    private function parseAnnulSoapResponse(string $soapResponse): array
    {
        preg_match('/<(?:result|return)>(.*?)<\/(?:result|return)>/s', $soapResponse, $resultMatches);
        if (!isset($resultMatches[1])) {
            preg_match('/<faultstring>(.*?)<\/faultstring>/s', $soapResponse, $errorMatches);
            return ['success' => false, 'error' => html_entity_decode($errorMatches[1] ?? 'Formato SOAP no reconocido al anular.')];
        }

        $decodedResult = trim(html_entity_decode($resultMatches[1]));
        $xml = @simplexml_load_string($decodedResult);
        if ($xml !== false) {
            $resultado = (string) ($xml->RESULTADO ?? $xml->Resultado ?? '');
            if (stripos($resultado, 'ERROR') !== false) {
                return ['success' => false, 'error' => $resultado];
            }

            return [
                'success' => true,
                'message' => $resultado !== '' ? $resultado : 'Documento anulado correctamente.',
                'serie' => (string) ($xml->SERIE ?? $xml->Serie ?? ''),
                'preimpreso' => (string) ($xml->PREIMPRESO ?? $xml->Preimpreso ?? ''),
            ];
        }

        if (stripos($decodedResult, 'ERROR') !== false) {
            return ['success' => false, 'error' => $decodedResult];
        }

        return ['success' => true, 'message' => $decodedResult !== '' ? $decodedResult : 'Documento anulado correctamente.'];
    }

    private function getNumericDocType(string $documentType): int
    {
        return match (strtoupper(trim($documentType))) {
            'FACT' => 1,
            'FCAM' => 2,
            'FPEQ' => 3,
            'FCAP' => 4,
            'FESP' => 5,
            'NABN' => 6,
            'RDON' => 7,
            'RECI' => 8,
            'NDEB' => 9,
            'NCRE' => 10,
            default => throw new Exception("No existe código SAT para tipo {$documentType}"),
        };
    }

    private function resolveServiceUrl(array $config): string
    {
        $mode = strtolower(trim((string) ($config['mode'] ?? '')));
        $prod = trim((string) ($config['url_prod'] ?? ''));
        $test = trim((string) ($config['url_test'] ?? ''));

        if ($mode === 'prod' && $prod !== '') {
            return $prod;
        }
        if ($mode === 'test' && $test !== '') {
            return $test;
        }
        if ($prod !== '') {
            return $prod;
        }
        if ($test !== '') {
            return $test;
        }

        throw new Exception('No se configuró URL de Guatefacturas.');
    }
}
