<?php
declare(strict_types=1);

/**
 * Posted-form state: keep good values (green), clear and flag bad ones (red).
 * Instantiate per form. Login pages do not use this.
 */
final class Form
{
    /** @var array<string,string> */
    private array $values = [];
    /** @var array<string,string> */
    private array $errors = [];
    private bool $posted = false;

    public function grab(array $src, string ...$keys): self
    {
        $this->posted = true;
        foreach ($keys as $k) {
            $this->values[$k] = trim((string) ($src[$k] ?? ''));
        }
        return $this;
    }

    public function fail(string $field, string $msg): void
    {
        $this->errors[$field] = $msg;
    }

    public function ok(): bool
    {
        return $this->posted && $this->errors === [];
    }

    public function bad(): bool
    {
        return $this->errors !== [];
    }

    public function get(string $field): string
    {
        return $this->values[$field] ?? '';
    }

    public function put(string $field, string $v): void
    {
        $this->values[$field] = $v;
    }

    /** Value to show: empty when this field failed. */
    public function keep(string $field): string
    {
        return isset($this->errors[$field]) ? '' : ($this->values[$field] ?? '');
    }

    public function cls(string $field, string $extra = ''): string
    {
        $mark = '';
        if ($this->posted) {
            $mark = isset($this->errors[$field]) ? 'noticered' : 'noticegreen';
        }
        return trim($extra . ' ' . $mark);
    }

    public function note(string $field): string
    {
        if (!isset($this->errors[$field])) {
            return '';
        }
        return '<span class="sans noticered field-hint">' . h($this->errors[$field]) . '</span>';
    }

    public function input(string $name, string $type = 'text', string $attrs = '', string $extraClass = ''): string
    {
        $cls = $this->cls($name, $extraClass);
        $html = '<input type="' . h($type) . '" name="' . h($name) . '"';
        if (!preg_match('/\bid\s*=/i', $attrs)) {
            $html .= ' id="' . h($name) . '"';
        }
        if ($cls !== '') {
            $html .= ' class="' . h($cls) . '"';
        }
        $html .= ' value="' . h($this->keep($name)) . '"';
        if ($attrs !== '') {
            $html .= ' ' . $attrs;
        }
        $html .= '>' . $this->note($name);
        return $html;
    }

    /** Named options; posted value is the array key. */
    public function select(string $name, array $options, string $empty = '', string $extraClass = 'formselect', string $attrs = ''): string
    {
        return form_select($name, $options, $this->keep($name), $empty, $this->cls($name, $extraClass), $attrs) . $this->note($name);
    }
}
