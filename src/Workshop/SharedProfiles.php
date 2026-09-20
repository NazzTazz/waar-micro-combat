<?php
namespace Waar\MicroCombat\Workshop;

final class SharedProfiles
{
    private string $directory;
    public function __construct(?string $directory=null){$this->directory=$directory??(getenv('WAAR_PROFILE_DIRECTORY')?:dirname(__DIR__,2).'/reports/shared-profiles');}
    private function read():array{$file=$this->directory.'/profiles.json';return is_file($file)?json_decode(file_get_contents($file),true,128,JSON_THROW_ON_ERROR):[];}
    public function listing():array{$rows=array_values($this->read());usort($rows,static fn($a,$b)=>strcmp($b['createdAt'],$a['createdAt']));return ['profiles'=>array_map(static fn($row)=>['id'=>$row['id'],'name'=>$row['name'],'createdAt'=>$row['createdAt']],$rows)];}
    public function load(string $id):array{$rows=$this->read();if(!isset($rows[$id]))throw new \RuntimeException('Sauvegarde introuvable.',404);return $rows[$id];}
    public function save(string $name,array $profile):array{
        $name=trim($name);if($name===''||strlen($name)>200||preg_match('/[\x00-\x1f]/',$name))throw new \InvalidArgumentException('Nom de profil invalide.');
        $profile['label']=$name;$profile['id']='saved-'.substr(hash('sha256',$name),0,24);
        $errors=EngineProfile::validate($profile);if($errors)throw new ProfileValidationException($errors);
        if(!is_dir($this->directory)&&!mkdir($this->directory,0700,true)&&!is_dir($this->directory))throw new \RuntimeException('Stockage indisponible.',503);
        $lock=fopen($this->directory.'/profiles.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new \RuntimeException('Stockage indisponible.',503);
        try{$rows=$this->read();foreach($rows as$row)if(strcasecmp($row['name'],$name)===0)throw new \RuntimeException('Ce nom existe déjà. Choisissez un autre nom ou numéro de proposition.',409);
            $id=bin2hex(random_bytes(16));$row=['id'=>$id,'name'=>$name,'createdAt'=>gmdate('c'),'profile'=>$profile];$rows[$id]=$row;
            $temporary=tempnam($this->directory,'profiles-');if($temporary===false)throw new \RuntimeException('Stockage indisponible.',503);
            $target=$this->directory.'/profiles.json';
            try{
                if(file_put_contents($temporary,json_encode($rows,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))===false)throw new \RuntimeException('Écriture impossible.',503);
                // Never move or delete the committed store to work around a failed
                // replacement. Readers keep seeing the previous complete document.
                if(!@rename($temporary,$target))throw new \RuntimeException('Écriture impossible. Les profils existants sont conservés.',503);
            }finally{if(is_file($temporary))unlink($temporary);}
            return $row;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}
