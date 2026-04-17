<?php

declare(strict_types=1);

namespace Meridian\Domain\Auth;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserSecurityAnswer extends Model
{
    protected $table = 'user_security_answers';
    public $timestamps = true;
    protected $hidden = ['answer_ciphertext'];
    protected $fillable = ['user_id', 'security_question_id', 'answer_ciphertext', 'key_version'];

    public function question(): BelongsTo
    {
        return $this->belongsTo(SecurityQuestion::class, 'security_question_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
