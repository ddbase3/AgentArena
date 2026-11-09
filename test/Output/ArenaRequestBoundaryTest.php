<?php declare(strict_types=1);

namespace AgentArena\Test\Output;

use AgentArena\Output\ArenaApiOutput;
use Base3\Api\IRequest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/** Verify the same-origin JSON policy without launching a match or an LLM. */
final class ArenaRequestBoundaryTest extends TestCase {

	private function check(array $headers): void {
		$request = $this->createMock(IRequest::class);
		$request->method('server')->willReturnCallback(
			static fn(string $name, mixed $default = null) => $headers[$name] ?? $default
		);
		$api = (new ReflectionClass(ArenaApiOutput::class))->newInstanceWithoutConstructor();
		(new ReflectionClass(ArenaApiOutput::class))->getProperty('request')->setValue($api, $request);
		(new ReflectionMethod(ArenaApiOutput::class, 'requireSameOriginJson'))->invoke($api);
	}

	public function testSameOriginJsonAccepted(): void {
		$this->expectNotToPerformAssertions();
		$this->check([
			'HTTP_SEC_FETCH_SITE' => 'same-origin',
			'CONTENT_TYPE' => 'application/json; charset=UTF-8'
		]);
	}

	public function testCrossSiteRejected(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('arena.invalid_origin');
		$this->check([
			'HTTP_SEC_FETCH_SITE' => 'cross-site',
			'CONTENT_TYPE' => 'application/json'
		]);
	}

	public function testSameSiteDifferentOriginRejected(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('arena.invalid_origin');
		$this->check([
			'HTTP_SEC_FETCH_SITE' => 'same-site',
			'CONTENT_TYPE' => 'application/json'
		]);
	}

	public function testMissingFetchMetadataRejected(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('arena.invalid_origin');
		$this->check(['CONTENT_TYPE' => 'application/json']);
	}

	public function testFormPostRejected(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('arena.invalid_content_type');
		$this->check([
			'HTTP_SEC_FETCH_SITE' => 'same-origin',
			'CONTENT_TYPE' => 'application/x-www-form-urlencoded'
		]);
	}

	public function testTextPlainJsonPostRejected(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('arena.invalid_content_type');
		$this->check([
			'HTTP_SEC_FETCH_SITE' => 'same-origin',
			'CONTENT_TYPE' => 'text/plain'
		]);
	}
}
