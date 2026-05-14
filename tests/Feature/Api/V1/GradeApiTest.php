<?php

use App\Jobs\ProcessGradeJob;
use App\Models\Grade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('lists grades paginated', function () {
    Grade::factory()->count(3)->create();

    $response = $this->getJson('/api/v1/grades');

    $response->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.type', 'grades')
        ->assertJsonStructure([
            'data' => [
                ['type', 'id', 'attributes' => ['name', 'subject', 'score', 'horizon_processed', 'ago', 'created_at', 'updated_at']],
            ],
            'links',
            'meta',
        ]);
});

it('stores a grade and dispatches ProcessGradeJob', function () {
    Queue::fake();

    $payload = ['name' => 'Ahmed', 'subject' => 'Math', 'score' => 90];

    $response = $this->postJson('/api/v1/grades', $payload);

    $response->assertCreated()
        ->assertJsonPath('data.type', 'grades')
        ->assertJsonPath('data.attributes.name', 'Ahmed')
        ->assertJsonPath('data.attributes.subject', 'Math')
        ->assertJsonPath('data.attributes.score', 90);

    $this->assertDatabaseHas('grades', $payload);

    Queue::assertPushed(ProcessGradeJob::class);
});

it('validates store payload', function () {
    $this->postJson('/api/v1/grades', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'subject', 'score']);

    $this->postJson('/api/v1/grades', ['name' => 'x', 'subject' => 'y', 'score' => 150])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['score']);
});

it('shows a grade by id', function () {
    $grade = Grade::factory()->create();

    $this->getJson("/api/v1/grades/{$grade->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $grade->id)
        ->assertJsonPath('data.type', 'grades')
        ->assertJsonPath('data.attributes.name', $grade->name);
});

it('returns 404 for missing grade', function () {
    $this->getJson('/api/v1/grades/'.fake()->uuid())
        ->assertStatus(404)
        ->assertJsonPath('status', 404);
});

it('deletes a grade', function () {
    $grade = Grade::factory()->create();

    $this->deleteJson("/api/v1/grades/{$grade->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Grade Deleted Successfully');

    $this->assertDatabaseMissing('grades', ['id' => $grade->id]);
});

it('returns 404 when deleting a missing grade', function () {
    $this->deleteJson('/api/v1/grades/'.fake()->uuid())
        ->assertStatus(404);
});

it('invalidates cache on store', function () {
    Queue::fake();

    Grade::factory()->count(2)->create();
    $this->getJson('/api/v1/grades')->assertJsonCount(2, 'data');

    $this->postJson('/api/v1/grades', ['name' => 'New', 'subject' => 'Bio', 'score' => 80])
        ->assertCreated();

    $this->getJson('/api/v1/grades')->assertJsonCount(3, 'data');
});

it('filters grades by subject', function () {
    Grade::factory()->create(['subject' => 'Math']);
    Grade::factory()->create(['subject' => 'Math']);
    Grade::factory()->create(['subject' => 'Science']);

    $this->getJson('/api/v1/grades?filter[subject]=Math')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('filters grades by name partial match', function () {
    Grade::factory()->create(['name' => 'Ahmed Ali']);
    Grade::factory()->create(['name' => 'Ahmed Khan']);
    Grade::factory()->create(['name' => 'Sara']);

    $this->getJson('/api/v1/grades?filter[name]=Ahmed')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('filters grades by min_score and max_score', function () {
    Grade::factory()->create(['score' => 50]);
    Grade::factory()->create(['score' => 75]);
    Grade::factory()->create(['score' => 95]);

    $this->getJson('/api/v1/grades?filter[min_score]=70&filter[max_score]=90')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.attributes.score', 75);
});

it('sorts grades by score descending', function () {
    Grade::factory()->create(['score' => 50]);
    Grade::factory()->create(['score' => 95]);
    Grade::factory()->create(['score' => 75]);

    $response = $this->getJson('/api/v1/grades?sort=-score')->assertOk();
    $scores = array_column(array_column($response->json('data'), 'attributes'), 'score');
    expect($scores)->toBe([95, 75, 50]);
});

it('rejects disallowed filters', function () {
    $this->getJson('/api/v1/grades?filter[nope]=x')
        ->assertStatus(400);
});

it('supports sparse fieldsets', function () {
    Grade::factory()->create();

    $this->getJson('/api/v1/grades?fields[grades]=name,score')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                ['type', 'id', 'attributes' => ['name', 'score']],
            ],
        ])
        ->assertJsonMissingPath('data.0.attributes.subject');
});
