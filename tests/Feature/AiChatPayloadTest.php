<?php

namespace Tests\Feature;

use App\Services\AiChatPayload;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AiChatPayloadTest extends TestCase
{
    public function test_source_whitespace_and_unicode_are_preserved(): void
    {
        $source = " \n<?php // 日本語 😀\r\nSELECT * FROM tasks;\n  ";
        $request = Request::create('/', 'POST', ['file_content_base64' => base64_encode($source)]);
        AiChatPayload::decode($request);
        $this->assertSame($source, $request->input('file_content'));
    }

    public function test_oversized_encoding_is_rejected_before_decoding(): void
    {
        $this->expectException(ValidationException::class);
        AiChatPayload::decode(Request::create('/', 'POST', ['content_base64' => str_repeat('A', 21337)]));
    }
}
