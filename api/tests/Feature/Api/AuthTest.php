<?php

use App\Models\User;

it('logs in and returns a token plus the user', function () {
    $user = userWithRole('admin');

    $res = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('data.type', 'users')
        ->assertJsonPath('data.attributes.role', 'admin');

    $this->withToken($res->json('meta.token'))->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', (string) $user->id);
});

it('rejects bad credentials with a JSON:API error', function () {
    userWithRole();
    $this->postJson('/api/v1/auth/login', ['email' => User::first()->email, 'password' => 'wrong'])
        ->assertStatus(422)
        ->assertJsonPath('errors.0.code', 'validation_failed')
        ->assertJsonPath('errors.0.source.pointer', '/email');
});

it('requires authentication', function () {
    $this->getJson('/api/v1/documents')->assertStatus(401)->assertJsonPath('errors.0.code', 'unauthenticated');
});

it('revokes the token on logout', function () {
    $user = userWithRole();
    $token = $user->createToken('t')->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
});

it('uses session cookies for the SPA origin (Sanctum stateful)', function () {
    $user = userWithRole('reviewer');
    $spa = ['Origin' => 'http://localhost:3030', 'Referer' => 'http://localhost:3030/login'];

    $this->get('/sanctum/csrf-cookie', $spa)->assertNoContent()->assertCookie('XSRF-TOKEN');

    $this->withHeaders($spa)->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('meta.auth', 'session')
        ->assertJsonMissingPath('meta.token');

    $this->withHeaders($spa)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', (string) $user->id);
    $this->assertAuthenticatedAs($user, 'web');

    $this->withHeaders($spa)->postJson('/api/v1/auth/logout')->assertNoContent();
    $this->assertGuest('web');
});

it('issues a token to non-browser clients', function () {
    $user = userWithRole('reviewer');

    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk()->assertJsonPath('meta.auth', 'token')->assertJsonStructure(['meta' => ['token']]);
});
