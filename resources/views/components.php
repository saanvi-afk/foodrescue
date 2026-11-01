<?php
function field(string $name,string $label,string $type='text',mixed $value='',string $attributes=''): void {
    static $counter=0;$id='field-' . ++$counter;
    echo '<label for="' . $id . '">' . e($label) . '</label>';
    if ($type==='textarea') { echo '<textarea id="' . $id . '" name="' . e($name) . '" rows="3" ' . $attributes . '>' . e($value) . '</textarea>'; }
    else { echo '<input id="' . $id . '" name="' . e($name) . '" type="' . e($type) . '" value="' . e($value) . '" ' . ($type==='datetime-local' ? 'data-utc="' . e($value) . '" ' : '') . $attributes . '>'; }
}
function choice(string $name,string $label,array $choices,mixed $value='',bool $required=true): void {
    static $counter=0;$id='select-' . ++$counter;echo '<label for="' . $id . '">' . e($label) . '</label><select id="' . $id . '" name="' . e($name) . '" ' . ($required ? 'required' : '') . '>';
    foreach ($choices as $key=>$text) { echo '<option value="' . e($key) . '" ' . ((string)$key===(string)$value ? 'selected' : '') . '>' . e($text) . '</option>'; }echo '</select>';
}
function checkfield(string $name,string $label,bool $checked=false): void { echo '<label class="check-field"><input type="checkbox" name="' . e($name) . '" value="1" ' . ($checked ? 'checked' : '') . '> <span>' . e($label) . '</span></label>'; }
function formend(string $label='Save changes'): void { echo '<p class="form-message" role="alert" tabindex="-1" hidden></p><button class="button" type="submit">' . e($label) . '</button></form>'; }
function stamp(string $date): void { echo '<time datetime="' . e(str_replace(' ','T',$date)) . 'Z" data-local-time>' . e($date) . ' UTC</time>'; }
function badge(string $status): void { echo '<span class="badge status-' . e($status) . '">' . e(ucfirst($status)) . '</span>'; }
