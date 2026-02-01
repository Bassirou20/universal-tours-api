<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Client;

uses(RefreshDatabase::class);

it('liste les clients (auth sanctum)', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Client::factory()->create(['nom' => 'Sow']);
    $response = $this->getJson('/api/clients');
    $response->assertOk();
});
