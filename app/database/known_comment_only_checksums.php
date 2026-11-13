<?php

declare(strict_types=1);

// HISTORICAL (MySQL era). Commit 1a5de46 added documentation comments to the
// then-applied MySQL migrations 001-013; these pairs let scripts/migrate.php
// accept a database that recorded the commented variant. Commit 3035b60
// rewrote 001-020 for PostgreSQL, so no PostgreSQL database can match these
// hashes and the exception never applies today. Kept so the runner's rule is
// unchanged; unknown checksums still fail. Never rewrite a recorded checksum.
return [
    '001' => ['canonical' => 'fe00f15be3097e043c886eb72c451888ceddad3faa8d42f07d26fb1e515cbb9b', 'comment_only' => '77983f96f57a39bf4fbc08d0132e96a0e0aacd09cd18afbd66e53a00960fb530'],
    '002' => ['canonical' => 'a3257447b1d41d23dba1a6bd24b068ec359d45dd87cd36f3d7ec3cb7b220c7ce', 'comment_only' => 'c3deca22d3f866d164fd8bc6c5231c91ed49a8c3cbd8c13728e6edaa377d3193'],
    '003' => ['canonical' => '79254a5fb68aa067d94dc6af9d1a089d9e38fdd3d205c58f517adb3033613dc3', 'comment_only' => '15726ffb59a616945637d219d7aad43c517f2fa1a911562bdeedd2d96deaba45'],
    '004' => ['canonical' => 'e6d33a407be03b3768ff5bfc8a274b9e9d9062b1346e2a31b5977a706b201557', 'comment_only' => 'b8f45f7d18b734cf7a8d3494ec35b4cc6b2e62b005e8ecc20f4301e84f44421a'],
    '005' => ['canonical' => '59ae049e9262c368627bceabf9f0c76b46344a8156e7828f3655ae43473db6a0', 'comment_only' => '84e63cbec6cd6f488dcbd21469d08057e856e27adfab550fccc34b7db7124d31'],
    '006' => ['canonical' => '990374ed4cff7bf1d7fa1d236bb9eb43289ccf189029302b36f995fb89658de6', 'comment_only' => 'd7af8e56e7ee536636e459cc865eee9a11c41e22efbfd38821e1665307a3a9a3'],
    '007' => ['canonical' => 'e2fd7d5bd59569bb571277369a25e67cf0a157c4ea0ff623bbb8601da1396757', 'comment_only' => '551f6033677819d871264dfbfc522a48d8f69f5362b64004cd7a3c9a0bb5856b'],
    '008' => ['canonical' => 'f46bdbb8842a0b80ac6e0fbcf51797b7419f86d0aa1522d47aa4adb0afd4540d', 'comment_only' => 'd73a4882794035ae2673ce351ee9b64684e3c28bacb1fcbca9750ee507a46406'],
    '009' => ['canonical' => '28bf1152718bfc16e1c05769af729ba222916ab7c8c2f91a1d1907ad14a23825', 'comment_only' => '2ff3ceca44377b7e3f89724c2f4dc9159b9c7919de14212a6d9d9377c509b022'],
    '010' => ['canonical' => 'cb508a5f05e5f6d58ddbc22c86026f0b579b7e12bc8dcbf5fea9986e2ad45e4e', 'comment_only' => 'd3f6d1945b5ead63e9cf92849c3a23371369c83de795fcb73fe2092d8e4482e5'],
    '011' => ['canonical' => 'c6e7fe24c6b35c54113b73b6c8a422845ac4299e66f990b653016d57b31c5d79', 'comment_only' => 'dd1aea6aa9842634142daba56029bc349b1ad0fc545b655e36e4a744e1fd290c'],
    '012' => ['canonical' => '28ffa72a6cbfed40fa1cda086a2bb70de171813c16dc0b0d888c19c0ef08576a', 'comment_only' => '62082542622aadc52bf72a82e2b6b64175cacf50178412276e4c7201f00aa643'],
    '013' => ['canonical' => 'a6cb867ddff15decac1546599176212faed2c27d0961db266d2d5205a639bdf2', 'comment_only' => 'e0e1ebabd6ca322541f63def765ae90682b88666f645b7768047c86deb1ce661'],
];
