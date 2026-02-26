upper: {$text|upper}
lower: {$text|lower}
capitalize: {$text|capitalize}
trim: [{$padded|trim}]
join: {$tags|join:", "}
alphabet: {$slug_text|alphabet:"-"|lower}
nl2br: {$multiline|nl2br}
addslashes: {$quote|addslashes}