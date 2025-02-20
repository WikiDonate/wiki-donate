<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Talk extends Model
{
    protected $fillable = ['user_id', 'article_id', 'title',  'slug', 'sections', 'is_active'];

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
