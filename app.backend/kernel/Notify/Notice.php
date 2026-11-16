<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify;

use Acadly\Kernel\Access\Permission;

/**
 * The declaration of one notice (04 §3): the event, its words, who hears it,
 * on which channels, and whether it may be silenced. Declared once, in the
 * topic class. The catalogue, the linter and the dispatcher all read this.
 */
final class Notice
{
    /** Tokens every notice may use without declaring them. */
    public const array ALWAYS = ['institution', 'actor', 'contact', 'school_phone'];

    /** The longest `short()` wording, before tokens are filled: one SMS. */
    public const int SHORT_BUDGET = 160;

    private string $name = '';

    private ?NoticeCategory $category = null;

    /** @var list<string> */
    private array $facts = [];

    private string $subject = '';

    /** @var list<string> */
    private array $lines = [];

    private ?string $short = null;

    /** @var array<string, string> */
    private array $examples = [];

    private ?string $actionLabel = null;

    private ?string $actionPath = null;

    /** @var list<class-string<PartyResolver>> */
    private array $parties = [];

    private bool $desk = false;

    private bool $people = false;

    /** @var list<Permission> */
    private array $actedOnWith = [];

    /** @var list<Channel> */
    private array $defaultChannels = [Channel::InApp];

    /** @var list<Channel> */
    private array $allowedChannels = [Channel::InApp];

    private OptOut $optOut = OptOut::Allowed;

    private bool $urgent = false;

    private bool $startsOff = false;

    /** @var list<Channel> */
    private array $fallback = [];

    private function __construct(public readonly string $key) {}

    public static function make(string $key): self
    {
        return new self($key);
    }

    /** The topic's name in lists: the event, not one message about it. */
    public function named(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function category(NoticeCategory $category): self
    {
        $this->category = $category;

        return $this;
    }

    /** The facts the words may name, as `:fact`. The topic's `tokens()` gives their values. */
    public function facts(string ...$facts): self
    {
        $this->facts = array_values($facts);

        return $this;
    }

    public function subject(string $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    public function line(string $line): self
    {
        $this->lines[] = $line;

        return $this;
    }

    /** The words for SMS: short, and never sensitive detail. It points to the portal. */
    public function short(string $short): self
    {
        $this->short = $short;

        return $this;
    }

    /**
     * Example facts, for the preview on the Messages page. Shown only there.
     *
     * @param  array<string, string>  $examples
     */
    public function examples(array $examples): self
    {
        $this->examples = $examples;

        return $this;
    }

    /** @return array<string, string> */
    public function exampleFacts(): array
    {
        return $this->examples;
    }

    /** A link to the screen where the person acts. The path may use tokens. */
    public function action(string $label, string $path): self
    {
        $this->actionLabel = $label;
        $this->actionPath = $path;

        return $this;
    }

    /** @param  class-string<PartyResolver>  $resolver */
    public function toParty(string $resolver): self
    {
        $this->parties[] = $resolver;

        return $this;
    }

    /** Staff who subscribed to this topic hear it. Never found by role. */
    public function toDesk(): self
    {
        $this->desk = true;

        return $this;
    }

    /** The sender may name people to tell. */
    public function toPeople(): self
    {
        $this->people = true;

        return $this;
    }

    /** The permissions of whoever can act on it. Used to seed desk subscriptions. */
    public function actedOnWith(Permission ...$permissions): self
    {
        $this->actedOnWith = array_values($permissions);

        return $this;
    }

    /**
     * @param  list<Channel>  $default
     * @param  list<Channel>  $allowed
     */
    public function channels(array $default, array $allowed): self
    {
        $this->defaultChannels = $default;
        $this->allowedChannels = $allowed;

        return $this;
    }

    public function optOut(OptOut $optOut): self
    {
        $this->optOut = $optOut;

        return $this;
    }

    /** Off until the school switches it on, on the Messages page. */
    public function startsOff(): self
    {
        $this->startsOff = true;

        return $this;
    }

    /** True unless the notice starts off. */
    public function onAtFirst(): bool
    {
        return ! $this->startsOff;
    }

    /** Passes quiet hours. When a channel fails, the next channel in the chain is tried. */
    public function urgent(Channel ...$fallback): self
    {
        $this->urgent = true;
        $this->fallback = array_values($fallback);

        return $this;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function categoryOf(): ?NoticeCategory
    {
        return $this->category;
    }

    /** @return list<string> */
    public function factNames(): array
    {
        return $this->facts;
    }

    public function subjectText(): string
    {
        return $this->subject;
    }

    /** @return list<string> */
    public function lines(): array
    {
        return $this->lines;
    }

    public function shortText(): ?string
    {
        return $this->short;
    }

    public function actionLabel(): ?string
    {
        return $this->actionLabel;
    }

    public function actionPath(): ?string
    {
        return $this->actionPath;
    }

    /** @return list<class-string<PartyResolver>> */
    public function parties(): array
    {
        return $this->parties;
    }

    public function hasDesk(): bool
    {
        return $this->desk;
    }

    public function allowsPeople(): bool
    {
        return $this->people;
    }

    /** @return list<string> permission keys */
    public function actingPermissions(): array
    {
        return array_map(fn (Permission $permission): string => $permission->key(), $this->actedOnWith);
    }

    /** @return list<Channel> */
    public function defaultChannels(): array
    {
        return $this->defaultChannels;
    }

    /** @return list<Channel> */
    public function allowedChannels(): array
    {
        return $this->allowedChannels;
    }

    public function optOutRule(): OptOut
    {
        return $this->optOut;
    }

    public function isUrgent(): bool
    {
        return $this->urgent;
    }

    /** @return list<Channel> */
    public function fallback(): array
    {
        return $this->fallback;
    }

    /**
     * Every template the words use: subject, lines, short, action label and path.
     *
     * @return list<string>
     */
    public function templates(): array
    {
        return array_values(array_filter([$this->subject, ...$this->lines, $this->short, $this->actionLabel, $this->actionPath], 'is_string'));
    }

    /**
     * The canonical form, hashed into the catalogue and mirrored by `acadly:sync`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'category' => $this->category?->value,
            'facts' => $this->facts,
            'subject' => $this->subject,
            'lines' => $this->lines,
            'short' => $this->short,
            'action_label' => $this->actionLabel,
            'action_path' => $this->actionPath,
            'parties' => $this->parties,
            'desk' => $this->desk,
            'people' => $this->people,
            'acted_on_with' => $this->actingPermissions(),
            'default_channels' => self::values($this->defaultChannels),
            'allowed_channels' => self::values($this->allowedChannels),
            'opt_out' => $this->optOut->value,
            'urgent' => $this->urgent,
            'starts_off' => $this->startsOff,
            'fallback' => self::values($this->fallback),
        ];
    }

    /**
     * @param  list<Channel>  $channels
     * @return list<string>
     */
    public static function values(array $channels): array
    {
        return array_map(fn (Channel $channel): string => $channel->value, $channels);
    }
}
