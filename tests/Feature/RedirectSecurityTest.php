<?php

use Illuminate\Validation\ValidationException;

it('rejects protocol relative urls, traversal forms, encoded slashes, and CRLF injection', function (): void {
    createRedirectManager();

    expect(fn () => makeRedirect([
        'source_path' => '/protocol',
        'destination' => '//evil.example',
    ]))->toThrow(ValidationException::class, 'Protocol-relative destinations are not allowed');

    expect(fn () => makeRedirect([
        'source_path' => '/traversal',
        'destination' => '/../escape',
    ]))->toThrow(ValidationException::class, 'Traversal segments are not allowed');

    expect(fn () => makeRedirect([
        'source_path' => '/encoded%2Fslash',
        'destination' => '/new-path',
    ]))->toThrow(ValidationException::class, 'Encoded slashes and null bytes are not allowed');

    expect(fn () => makeRedirect([
        'source_path' => '/crlf',
        'destination' => "https://safe.example\r\nX-Test: injected",
    ]))->toThrow(ValidationException::class, 'Destination cannot contain line breaks');
});

it('does not redirect malformed encoded request paths', function (): void {
    createRedirectManager();
    makeRedirect([
        'source_path' => '/encoded-target',
        'destination' => '/new-path',
    ]);

    $this->get('https://www.example.test/encoded-target%2Fextra')
        ->assertNotFound();
});
