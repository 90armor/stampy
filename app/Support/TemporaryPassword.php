<?php

namespace App\Support;

class TemporaryPassword
{
    /**
     * How long a temporary password signs in for — one set by a reset, or
     * generated with a new login (Phase 6h, N1). Past it, login refuses it
     * (User::hasExpiredTemporaryPassword()) and an admin resets it again.
     */
    public const LIFETIME_HOURS = 48;

    /**
     * Short, name-like words rather than a random string — this travels by
     * paper, Telegram, or word of mouth, and needs to be read aloud or
     * copied by hand without inviting transcription errors. The random
     * digits, not the word, carry what little entropy matters here: the
     * password is temporary (48h), forces an immediate change, and login
     * is already rate-limited, so readability is the priority.
     */
    private const WORDS = [
        'Kyaw', 'Aye', 'Zaw', 'Thura', 'Htet', 'Nyan', 'Moe', 'Win', 'Soe', 'Aung',
        'Naing', 'Thiha', 'Pyae', 'Zin', 'Hein', 'Kaung', 'Wai', 'Chit', 'Tun', 'Lin',
        'Su', 'Ei', 'Nandar', 'Ohnmar', 'Yamin', 'Khaing', 'Sanda', 'Thet', 'Mya', 'Nge',
    ];

    /**
     * e.g. "Kyaw-4871".
     */
    public static function generate(): string
    {
        $word = self::WORDS[array_rand(self::WORDS)];
        $digits = random_int(1000, 9999);

        return "{$word}-{$digits}";
    }
}
