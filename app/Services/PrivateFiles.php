<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class PrivateFiles
{
    private function client()
    {
        $key = config('integra.supabase_key');
        $http = Http::withHeaders(['apikey' => $key])->timeout(30);

        return str_starts_with($key, 'eyJ') ? $http->withToken($key) : $http;
    }

    private function url(string $path): string
    {
        return rtrim(config('integra.supabase_url'), '/').'/storage/v1/'.$path;
    }

    public function put(string $path, string $bytes, string $mime): void
    {
        if (config('integra.files_driver') === 'supabase') {
            $this->client()->withBody($bytes, $mime)->post($this->url('object/'.config('integra.bucket').'/'.$path))->throw();
        } else {
            Storage::disk('local')->put($path, $bytes);
        }
    }

    public function get(string $path): string
    {
        return config('integra.files_driver') === 'supabase' ? $this->client()->get($this->url('object/authenticated/'.config('integra.bucket').'/'.$path))->throw()->body() : Storage::disk('local')->get($path);
    }
}
