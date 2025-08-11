<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MessageAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'message_id', 'disk', 'path', 'original_name', 'mime_type', 'size_bytes',
    ];

    public function message()
    {
        return $this->belongsTo(Message::class);
    }
}
