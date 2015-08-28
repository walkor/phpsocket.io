<?php
namespace Parser;
use \Parser\Parser;
use \Event\Emitter;
class Encoder extends Emitter 
{
    public function encode($obj, $callback)
    {
        if(Parser::BINARY_EVENT == $obj['type'] || Parser::BINARY_ACK == $obj['type']) 
        {
            self::encodeAsBinary($obj, $callback);
        }
        else 
        {
            $encoding = self::encodeAsString($obj);
            call_user_func($callback, array($encoding));
        }
    }

    public static function encodeAsString($obj) {
        $str = '';
        $nsp = false;

        // first is type
        $str .= $obj['type'];

        // attachments if we have them
        if (Parser::BINARY_EVENT == $obj['type'] || Parser::BINARY_ACK == $obj['type']) 
        {
            $str .= $obj['attachments'];
            $str .= '-';
        }

        // if we have a namespace other than `/`
        // we append it followed by a comma `,`
        if (!empty($obj['nsp']) && '/' !== $obj['nsp']) 
        {
            $nsp = true;
            $str .= $obj['nsp'];
        }

        // immediately followed by the id
        if (isset($obj['id'])) 
        {
            if($nsp)
            {
                $str .= ',';
                $nsp = false;
            }
            $str .= $obj['id'];
        }

        // json data
        if(isset($obj['data']))
        {
            if ($nsp) $str .= ',';
            $str .= json_encode($obj['data']);
        }

        return $str;
    }


    /**
 * Encode packet as 'buffer sequence' by removing blobs, and
 * deconstructing packet into object with placeholders and
 * a list of buffers.
 *
 * @param {Object} packet
 * @return {Buffer} encoded
 * @api private
 */
/*
    public static function encodeAsBinary($obj, $callback) 
    {
        binary.removeBlobs(obj, writeEncoding);
    }

    public static function writeEncoding($bloblessData)
    {
        deconstruction = binary.deconstructPacket(bloblessData);
        pack = encodeAsString(deconstruction.packet);
        buffers = deconstruction.buffers;
        buffers.unshift(pack);
        callback(buffers); 
    }
*/

    

}
