<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TalkRevision extends Model
{
    protected $fillable = ['talk_id', 'user_id',  'version', 'old_content', 'new_content', 'created_at'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = Str::uuid()->toString();
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class)->select('uuid', 'username');
    }
}
