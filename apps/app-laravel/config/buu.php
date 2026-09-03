<?php

/**
 * BUU Kong API Gateway (Develop / Production).
 *
 * Each endpoint has its own OAuth2 token URL:
 *   POST https://{domain}/{path}/oauth2/token
 * then the same path for the API call with Bearer token.
 *
 * @see API-Develop.pdf (kong-dev.buu.ac.th)
 */
return [

    'minio_enabled' => env('BUU_MINIO_ENABLED', false),

    'domain' => env('BUU_KONG_DOMAIN', 'https://kong-dev.buu.ac.th'),

    'client_id' => env('BUU_CLIENT_ID', ''),
    'client_secret' => env('BUU_CLIENT_SECRET', ''),

    /** Default authenticated_userid sent when requesting password-grant tokens */
    'authenticated_userid' => env('BUU_AUTHENTICATED_USERID', 'library-buu'),

    /** Default MinIO bucket for document uploads (must exist on the cluster) */
    'default_bucket' => env('BUU_MINIO_BUCKET', 'law-space'),

    /** System name sent to e-sign (doc_sysname) */
    'esign_sysname' => env('BUU_ESIGN_SYSNAME', 'law-space'),

    /**
     * Public base URL that BUU e-sign can POST back to.
     * Final callback: {base}/api/esign/callback/{documentId}
     */
    'esign_callback_base_url' => rtrim((string) env('BUU_ESIGN_CALLBACK_BASE_URL', env('APP_URL', 'http://localhost')), '/'),

    'timeout' => (int) env('BUU_HTTP_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Endpoint registry (path + OAuth scope / provision_key)
    |--------------------------------------------------------------------------
    */
    'endpoints' => [

        'ldap.login' => [
            'path' => 'service-api/ldap.loginBuu',
            'scope' => 'read',
            'provision_key' => env('BUU_PROVISION_LDAP_LOGIN', ''),
        ],

        'minio.list' => [
            'path' => 'service-api/minio.GetFilesList',
            'scope' => 'read',
            'provision_key' => env('BUU_PROVISION_MINIO_LIST', ''),
        ],

        'minio.put' => [
            'path' => 'service-api/minio.PutFile',
            'scope' => 'write',
            'provision_key' => env('BUU_PROVISION_MINIO_PUT', ''),
        ],

        'minio.public' => [
            'path' => 'service-api/minio.GetPublicFile',
            'scope' => 'read',
            'provision_key' => env('BUU_PROVISION_MINIO_PUBLIC', ''),
        ],

        'minio.delete' => [
            'path' => 'service-api/minio.DeleteFile',
            'scope' => 'write',
            'provision_key' => env('BUU_PROVISION_MINIO_DELETE', ''),
        ],

        'esign.send' => [
            'path' => 'e-sign/e-sign.SendDocumentSign',
            'scope' => 'write',
            'provision_key' => env('BUU_PROVISION_ESIGN_SEND', ''),
        ],

        'esign.cancel' => [
            'path' => 'e-sign/e-sign.CancelDocumentSign',
            'scope' => 'write',
            'provision_key' => env('BUU_PROVISION_ESIGN_CANCEL', ''),
        ],

        'esign.add_signer' => [
            'path' => 'e-sign/e-sign.AddDocumentSigner',
            'scope' => 'write',
            'provision_key' => env('BUU_PROVISION_ESIGN_ADD_SIGNER', ''),
        ],

        'esign.delete_signer' => [
            'path' => 'e-sign/e-sign.DeleteDocumentSigner',
            'scope' => 'write',
            'provision_key' => env('BUU_PROVISION_ESIGN_DELETE_SIGNER', ''),
        ],

        'esign.check_certificate' => [
            'path' => 'e-sign/e-sign.CheckCertificateAndSignatureByPerson',
            'scope' => 'read',
            'provision_key' => env('BUU_PROVISION_ESIGN_CHECK_CERT', ''),
        ],
    ],
];
