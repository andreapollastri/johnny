<?php

namespace App\Models;

use App\Support\KeyScopes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An access key that has the same permissions on every bucket.
 *
 * @property int $id
 * @property string $key_id
 * @property string $name
 * @property string $scope
 */
#[Fillable(['key_id', 'name', 'scope'])]
class GlobalKey extends Model
{
    public function accessLabel(): string
    {
        return KeyScopes::label($this->scope);
    }
}
