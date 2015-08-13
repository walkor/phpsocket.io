<?php
namespace Event;

class Emitter
{
    /**
     * [event1=>[listener1, listener2, ..], event2=>[listener1, listener2, ..] ..]
     * @var array
     */
    protected $allEvent = array();
    
}