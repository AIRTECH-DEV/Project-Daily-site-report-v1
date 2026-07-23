<?php
/**
 * Google Drive v3 helper for a SHARED DRIVE (service accounts have no My-Drive
 * quota, so files must live on a Workspace shared drive shared with the SA).
 * Every call sets supportsAllDrives / includeItemsFromAllDrives so shared-drive
 * folders are found and written. Ports getOrCreateProjectFolder + saveBase64File.
 */
class Drive
{
    const FILES = 'https://www.googleapis.com/drive/v3/files';
    const UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';

    /** @var GoogleClient */
    private $client;
    /** @var array */
    private $cfg;

    public function __construct(GoogleClient $client, array $cfg)
    {
        $this->client = $client;
        $this->cfg = $cfg;
    }

    /** Finds the per-project subfolder under the parent, creating it if absent. */
    public function getOrCreateProjectFolder(string $projectName): string
    {
        $parent = $this->cfg['parent_folder_id'];
        $name = $projectName !== '' ? $projectName : 'General_Reports';

        $existing = $this->findChildFolder($parent, $name);
        if ($existing !== null) {
            return $existing;
        }
        return $this->createFolder($parent, $name);
    }

    private function findChildFolder(string $parentId, string $name): ?string
    {
        $q = sprintf(
            "mimeType='application/vnd.google-apps.folder' and trashed=false and '%s' in parents and name='%s'",
            $parentId,
            str_replace("'", "\\'", $name)
        );
        $url = self::FILES . '?' . http_build_query([
            'q'                         => $q,
            'fields'                    => 'files(id,name)',
            'supportsAllDrives'         => 'true',
            'includeItemsFromAllDrives' => 'true',
            'corpora'                   => 'allDrives',
            'pageSize'                  => 10,
        ]);
        $res = $this->client->get($url);
        return $res['files'][0]['id'] ?? null;
    }

    private function createFolder(string $parentId, string $name): string
    {
        $url = self::FILES . '?' . http_build_query(['supportsAllDrives' => 'true', 'fields' => 'id']);
        $res = $this->client->post($url, [
            'name'     => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents'  => [$parentId],
        ]);
        return $res['id'];
    }

    /**
     * Uploads raw bytes into a folder via multipart. Returns
     * ['id'=>..., 'url'=>webViewLink, 'bytes'=>int].
     */
    public function uploadBytes(string $folderId, string $fileName, string $mimeType, string $bytes): array
    {
        $meta = ['name' => $fileName, 'parents' => [$folderId]];
        $boundary = 'pmsbnd' . bin2hex(random_bytes(8));

        $body = "--$boundary\r\n"
            . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
            . json_encode($meta) . "\r\n"
            . "--$boundary\r\n"
            . "Content-Type: $mimeType\r\n\r\n"
            . $bytes . "\r\n"
            . "--$boundary--";

        $url = self::UPLOAD . '?' . http_build_query([
            'uploadType'        => 'multipart',
            'supportsAllDrives' => 'true',
            'fields'            => 'id,webViewLink,size',
        ]);
        $res = $this->client->raw('POST', $url, $body, "multipart/related; boundary=$boundary");
        return [
            'id'    => $res['id'] ?? '',
            'url'   => $res['webViewLink'] ?? ('https://drive.google.com/file/d/' . ($res['id'] ?? '') . '/view'),
            'bytes' => (int)($res['size'] ?? strlen($bytes)),
        ];
    }

    /** Decodes a {base64,mimeType,name} file object and uploads it; null if empty. */
    public function saveBase64File(?array $fileObj, string $folderId, string $prefix): ?array
    {
        if (!$fileObj || empty($fileObj['base64'])) {
            return null;
        }
        $bytes = base64_decode($fileObj['base64'], true);
        if ($bytes === false) {
            return null;
        }
        $name = $prefix . '_' . ($fileObj['name'] ?? 'file');
        $mime = $fileObj['mimeType'] ?? 'application/octet-stream';
        $up = $this->uploadBytes($folderId, $name, $mime, $bytes);
        return $up + ['drive_file_id' => $up['id'], 'file_name' => $name, 'mime_type' => $mime];
    }

    /** Shares a file as anyone-with-link viewer (so report links open for clients). */
    public function makeLinkViewable(string $fileId): void
    {
        $url = self::FILES . '/' . rawurlencode($fileId) . '/permissions?'
            . http_build_query(['supportsAllDrives' => 'true']);
        $this->client->post($url, ['role' => 'reader', 'type' => 'anyone']);
    }

    /** Trash a file (used for cleanup of test uploads). */
    public function trash(string $fileId): void
    {
        $url = self::FILES . '/' . rawurlencode($fileId) . '?'
            . http_build_query(['supportsAllDrives' => 'true']);
        $this->client->raw('PATCH', $url, json_encode(['trashed' => true]), 'application/json');
    }
}
