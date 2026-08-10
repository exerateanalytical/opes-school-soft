<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An outbound integration endpoint a school has registered - "notify this
 * URL when a fee invoice is issued", etc.
 *
 * `secret` is stored raw (TEXT, not hashed): it is an HMAC shared key used
 * to SIGN every delivery, not a password - the server must be able to read
 * it back to compute the signature on each send. Shown to the operator
 * exactly once, at creation, the same convention as an API token.
 *
 * @property int $id
 * @property string $name
 * @property string $url
 * @property string $secret
 * @property array<int, string> $events
 * @property bool $is_active
 */
final class WebhookEndpoint extends Model
{
    protected $table = 'webhook_endpoints';

    /** @var list<string> */
    protected $fillable = ['name', 'url', 'secret', 'events', 'is_active', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['events' => 'array', 'is_active' => 'boolean'];
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'webhook_endpoint_id');
    }
}
