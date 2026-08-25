<?php

namespace PHPSocketIO\Event;

class Emitter
{
    /**
     * [event=>[[listener1, once?], [listener2,once?], ..], ..]
     */
    protected array $_eventListenerMap = [];

    public function on(string $event_name, callable $listener): Emitter
    {
        $this->emit('newListener', $event_name, $listener);
        $this->_eventListenerMap[$event_name][] = [$listener, 0];
        return $this;
    }

    public function once(string $event_name, callable $listener): Emitter
    {
        $this->_eventListenerMap[$event_name][] = [$listener, 1];
        return $this;
    }

    public function removeListener(string $event_name, callable $listener): Emitter
    {
        if (! isset($this->_eventListenerMap[$event_name])) {
            return $this;
        }
        foreach ($this->_eventListenerMap[$event_name] as $key => $item) {
            if ($item[0] === $listener) {
                $this->emit('removeListener', $event_name, $listener);
                unset($this->_eventListenerMap[$event_name][$key]);
            }
        }
        if (empty($this->_eventListenerMap[$event_name])) {
            unset($this->_eventListenerMap[$event_name]);
        }
        return $this;
    }

    public function removeAllListeners(?string $event_name = null): Emitter
    {
        $this->emit('removeListener', $event_name);
        if (null === $event_name) {
            $this->_eventListenerMap = [];
            return $this;
        }
        unset($this->_eventListenerMap[$event_name]);
        return $this;
    }

    public function listeners(string $event_name): array
    {
        if (empty($this->_eventListenerMap[$event_name])) {
            return [];
        }
        $listeners = [];
        foreach ($this->_eventListenerMap[$event_name] as $item) {
            $listeners[] = $item[0];
        }
        return $listeners;
    }

    public function emit($event_name = null)
    {
        if (empty($event_name) || empty($this->_eventListenerMap[$event_name])) {
            return false;
        }
        foreach ($this->_eventListenerMap[$event_name] as $key => $item) {
            $args = func_get_args();
            unset($args[0]);
            call_user_func_array($item[0], $args);
            // once ?
            if ($item[1]) {
                unset($this->_eventListenerMap[$event_name][$key]);
                if (empty($this->_eventListenerMap[$event_name])) {
                    unset($this->_eventListenerMap[$event_name]);
                }
            }
        }
        return true;
    }
}
