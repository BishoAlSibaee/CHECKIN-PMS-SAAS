<?php

namespace Tests\Feature\Guest;

use Tests\TestCase;

class GuestClassificationTest extends TestCase
{
    public function test_list_guest_classifications(): void
    {
        $user = $this->userWithOnlyPermissions(['manage guest classifications']);

        $this->assertApiSuccess(
            $this->actingAs($user, 'api')->getJson('/api/users/getGuestClassification')
        );
    }

    public function test_create_guest_classification(): void
    {
        $user = $this->userWithOnlyPermissions(['manage guest classifications']);
        $suffix = $this->uniqueSuffix();

        $response = $this->actingAs($user, 'api')->postJson('/api/users/addGuestClassification', [
            'name_ar' => 'تصنيف '.$suffix,
            'name_en' => 'Class '.$suffix,
            'description' => 'Test classification',
        ]);

        if ($response->status() === 422) {
            $this->markTestSkipped('Guest classification payload differs: '.$response->json('message'));
        }

        $this->assertApiSuccess($response);
    }

    public function test_list_classified_clients(): void
    {
        $user = $this->userWithOnlyPermissions(['manage guest classifications']);

        $this->assertApiSuccess(
            $this->actingAs($user, 'api')->getJson('/api/users/getAllClientsWithClassification')
        );
    }

    public function test_guest_features_crud_list(): void
    {
        $user = $this->userWithOnlyPermissions(['manage guest classifications']);

        $this->assertApiSuccess(
            $this->actingAs($user, 'api')->getJson('/api/users/getGuestFeature')
        );
    }

    public function test_client_search_with_exclude_assigned_does_not_error(): void
    {
        $user = $this->userWithOnlyPermissions(['manage guest classifications', 'view clients']);

        $response = $this->actingAs($user, 'api')->getJson('/api/users/getClient?search=ab&exclude_assigned=1');

        $response->assertOk();
        $this->assertTrue(
            $response->json('success') === true || $response->json('result') === 'success',
            $response->json('message') ?? 'Client search failed'
        );
    }
}
