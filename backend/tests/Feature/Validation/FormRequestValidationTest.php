<?php

namespace Tests\Feature\Validation;

use Tests\TestCase;

class FormRequestValidationTest extends TestCase
{
    /** @dataProvider invalidPostPayloadsProvider */
    public function test_post_endpoints_return_validation_error_on_empty_payload(string $uri, array $permissions): void
    {
        $user = $this->userWithOnlyPermissions($permissions);

        $this->assertApiValidationError(
            $this->actingAs($user, 'api')->postJson($uri, [])
        );
    }

    public static function invalidPostPayloadsProvider(): array
    {
        return [
            'building' => ['/api/users/building', ['manage buildings']],
            'client' => ['/api/users/addClient', ['manage clients']],
            'tax' => ['/api/users/addTax', ['manage taxes']],
            'stay reason' => ['/api/users/addStayReason', ['manage stay reasons']],
            'reservation source' => ['/api/users/addReservationSource', ['manage reservation sources']],
            'make reservation' => ['/api/users/makeReservation', ['create reservations']],
        ];
    }

    public function test_building_duplicate_number_rejected(): void
    {
        $user = $this->userWithOnlyPermissions(['manage buildings']);
        $existing = \App\Models\Building::where('active', 1)->first();
        $this->assertNotNull($existing);

        $response = $this->actingAs($user, 'api')->postJson('/api/users/building', [
            'name' => 'Duplicate Test ' . uniqid(),
            'number' => $existing->number,
            'numberOfFloor' => 1,
            'numberFloor' => 1,
        ]);

        $this->assertApiValidationError($response);
    }
}
