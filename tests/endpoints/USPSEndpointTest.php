<?php declare(strict_types=1);

namespace Tests\VerifierServer\Endpoints;

use PHPUnit\Framework\TestCase;
use VerifierServer\Endpoints\USPSEndpoint;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

class USPSEndpointTest extends TestCase
{
    private USPSEndpoint $endpoint;

    protected function setUp(): void
    {
        $this->endpoint = new USPSEndpoint('test_userid');
    }

    public function testHandleGet(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([
            'address2' => '6406 Ivy Lane',
            'city' => 'Greenbelt',
            'state' => 'MD',
        ]);

        $response = 0;
        $headers = [];
        $body = '';

        $this->endpoint->handle('GET', $request, $response, $headers, $body);

        $this->assertEquals(Response::STATUS_OK, $response);
        $this->assertArrayHasKey('Content-Type', $headers);
        $this->assertEquals('application/xml; charset=UTF-8', $headers['Content-Type']);
        $this->assertNotEmpty($body);
    }

    public function testHandleHead(): void
    {
        $response = 0;
        $headers = [];
        $body = '';

        $content = $this->endpoint->handle('HEAD', '', $response, $headers, $body);

        $this->assertEquals(Response::STATUS_OK, $response);
        $this->assertArrayHasKey('Content-Type', $headers);
        $this->assertArrayHasKey('Content-Length', $headers);
        //$this->assertNotEmpty($content);
    }

    public function testHandlePost(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([
            'address2' => '6406 Ivy Lane',
            'city' => 'Greenbelt',
            'state' => 'MD',
        ]);

        $response = 0;
        $headers = [];
        $body = '';

        $this->endpoint->handle('POST', $request, $response, $headers, $body);

        $this->assertEquals(Response::STATUS_OK, $response);
        $this->assertArrayHasKey('Content-Type', $headers);
        $this->assertEquals('application/xml; charset=UTF-8', $headers['Content-Type']);
        $this->assertNotEmpty($body);
    }

    public function testHandleInvalidMethod(): void
    {
        $response = 0;
        $headers = [];
        $body = '';

        $this->endpoint->handle('INVALID', '', $response, $headers, $body);

        $this->assertEquals(Response::STATUS_METHOD_NOT_ALLOWED, $response);
        $this->assertArrayHasKey('Content-Type', $headers);
        $this->assertEquals('text/plain', $headers['Content-Type']);
        $this->assertEquals('Method Not Allowed', $body);
    }

    public function testGetWithMissingFields(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);

        $response = 0;
        $headers = [];
        $body = '';

        $this->endpoint->handle('GET', $request, $response, $headers, $body);

        $this->assertEquals(Response::STATUS_BAD_REQUEST, $response);
        $this->assertArrayHasKey('Content-Type', $headers);
        $this->assertEquals('text/plain', $headers['Content-Type']);
        $this->assertStringContainsString('is required', $body);
    }

    public function testXmlStringGeneration(): void
    {
        $reflection = new \ReflectionClass($this->endpoint);
        $method = $reflection->getMethod('__xmlstring');
        $method->setAccessible(true);

        $xmlString = $method->invoke($this->endpoint);

        $this->assertStringContainsString('<ZipCodeLookupRequest', $xmlString);
        $this->assertStringContainsString('<Address2>6406 IVY LANE</Address2>', $xmlString);
    }

    public function testContentGeneration(): void
    {
        $reflection = new \ReflectionClass($this->endpoint);
        $method = $reflection->getMethod('__content');
        $method->setAccessible(true);

        $content = $method->invoke($this->endpoint);

        $this->assertStringContainsString('<Info>Information provided by www.usps.com</Info>', $content);
    }
}