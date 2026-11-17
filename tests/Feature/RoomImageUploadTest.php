<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\RoomImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoomImageUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function makePngFile(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a9ioAAAAASUVORK5CYII=')
        );
    }

    public function test_landlord_can_upload_and_accumulate_images(): void
    {
        $landlord = User::factory()->landlord()->create();
        $room = Room::factory()->create(['landlord_id' => $landlord->id]);
        Sanctum::actingAs($landlord);

        // Upload first batch of 2 images
        $file1 = $this->makePngFile('photo1.png');
        $file2 = $this->makePngFile('photo2.png');

        $response1 = $this->postJson("/api/rooms/{$room->id}/images", [
            'images' => [$file1, $file2],
        ]);
        $response1->assertOk()
            ->assertJsonCount(2, 'data.images');
        $this->assertEquals(2, $room->images()->count());

        // Upload second batch of 2 images without replace: should accumulate to 4
        $file3 = $this->makePngFile('photo3.png');
        $file4 = $this->makePngFile('photo4.png');

        $response2 = $this->postJson("/api/rooms/{$room->id}/images", [
            'images' => [$file3, $file4],
        ]);
        $response2->assertOk()
            ->assertJsonCount(4, 'data.images');
        $this->assertEquals(4, $room->images()->count());
    }

    public function test_updating_room_details_does_not_delete_existing_images(): void
    {
        $landlord = User::factory()->landlord()->create();
        $room = Room::factory()->create(['landlord_id' => $landlord->id]);
        Sanctum::actingAs($landlord);

        RoomImage::create([
            'room_id' => $room->id,
            'path' => 'rooms/'.$room->id.'/sample.jpg',
            'original_name' => 'sample.jpg',
            'sort_order' => 1,
        ]);

        $this->putJson("/api/rooms/{$room->id}", [
            'room_number' => $room->room_number,
            'title' => 'Updated Title',
            'location' => $room->location,
            'price' => $room->price,
            'status' => 'available',
        ])->assertOk();

        $this->assertEquals(1, $room->images()->count());
    }

    public function test_landlord_can_explicitly_delete_an_individual_image(): void
    {
        $landlord = User::factory()->landlord()->create();
        $room = Room::factory()->create(['landlord_id' => $landlord->id]);
        Sanctum::actingAs($landlord);

        $image1 = RoomImage::create([
            'room_id' => $room->id,
            'path' => 'rooms/'.$room->id.'/img1.jpg',
            'original_name' => 'img1.jpg',
            'sort_order' => 1,
        ]);
        $image2 = RoomImage::create([
            'room_id' => $room->id,
            'path' => 'rooms/'.$room->id.'/img2.jpg',
            'original_name' => 'img2.jpg',
            'sort_order' => 2,
        ]);

        $response = $this->deleteJson("/api/rooms/{$room->id}/images/{$image1->id}");
        $response->assertOk()
            ->assertJsonPath('message', 'Image removed.');

        $this->assertDatabaseMissing('room_images', ['id' => $image1->id]);
        $this->assertDatabaseHas('room_images', ['id' => $image2->id]);
        $this->assertEquals(1, $room->images()->count());
    }

    public function test_room_rejects_more_than_8_total_images(): void
    {
        $landlord = User::factory()->landlord()->create();
        $room = Room::factory()->create(['landlord_id' => $landlord->id]);
        Sanctum::actingAs($landlord);

        // Create 6 existing images
        for ($i = 1; $i <= 6; $i++) {
            RoomImage::create([
                'room_id' => $room->id,
                'path' => "rooms/{$room->id}/img{$i}.jpg",
                'original_name' => "img{$i}.jpg",
                'sort_order' => $i,
            ]);
        }

        // Try adding 3 images (would total 9, exceeding limit 8)
        $files = [
            $this->makePngFile('extra1.png'),
            $this->makePngFile('extra2.png'),
            $this->makePngFile('extra3.png'),
        ];

        $response = $this->postJson("/api/rooms/{$room->id}/images", [
            'images' => $files,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('images');
        $this->assertEquals(6, $room->images()->count());

        // Adding 2 images (totals 8) succeeds
        $allowed = [
            $this->makePngFile('ok1.png'),
            $this->makePngFile('ok2.png'),
        ];
        $this->postJson("/api/rooms/{$room->id}/images", ['images' => $allowed])
            ->assertOk();
        $this->assertEquals(8, $room->images()->count());
    }
}
