<?php
/**
 * File
 * 
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class File {
	public $priority = 10; // low priority
	public $chunkSize = 4096;
	public $stat;
	public $offset = 0;
	public $fdCacheKey;
	public $append;
	public $path;
	public $writing = false;
	public $closed = false;

	/**
	 * File constructor
 	 * @param resource File descriptor
	 * @return void
	 */
	public function __construct($fd) {
		$this->fd = $fd;
		$this->onWriteOnce = new StackCallbacks;
	}

	public static function convertFlags($mode, $text = false) {
		$plus = strpos($mode, '+') !== false;
		$sync = strpos($mode, 's') !== false;
		$type = strtr($mode, array('b' => '', '+' => '', 's' => '', '!' => ''));
		if ($text) {
			return $type;
		}
		$types = array(
			'r' =>  $plus ? EIO_O_RDWR : EIO_O_RDONLY,
			'w' => ($plus ? EIO_O_RDWR : EIO_O_WRONLY) | EIO_O_CREAT | EIO_O_TRUNC,
			'a' => ($plus ? EIO_O_RDWR : EIO_O_WRONLY) | EIO_O_CREAT | EIO_O_APPEND,
			'x' => ($plus ? EIO_O_RDWR : EIO_O_WRONLY) | EIO_O_EXCL | EIO_O_CREAT,
			'c' => ($plus ? EIO_O_RDWR : EIO_O_WRONLY) | EIO_O_CREAT,
		);
		$m = $types[$type];
		if ($sync) {
			$m |= EIO_O_FSYNC;
		}
		return $m;
	}


	public function truncate($offset = 0, $cb = null, $pri = EIO_PRI_DEFAULT) {
		if (!$this->fd || $this->fd === -1) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FS::$supported) {
			$fp = fopen($this->path, 'r+');
			$r = $fp && ftruncate($fp, $offset);
			if ($cb) {
				call_user_func($cb, $this, $r);
			}
			return;
		}
		eio_ftruncate($this->fd, $offset, $pri, $cb, $this);
	}
	
	public function stat($cb, $pri = EIO_PRI_DEFAULT) {
		if (!$this->fd || $this->fd === -1) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FS::$supported) {
			call_user_func($cb, $this, FS::statPrepare(fstat($this->fd)));
			return;
		}
		if ($this->stat) {
			call_user_func($cb, $this, $this->stat);
		} else {
			eio_fstat($this->fd, $pri, function ($file, $stat) use ($cb) {
				$stat = FS::statPrepare($stat);
				$file->stat = $stat;
				call_user_func($cb, $file, $stat);
			}, $this);		
		}
	}

	public function statRefresh($cb, $pri = EIO_PRI_DEFAULT) {
		if (!$this->fd || $this->fd === -1) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FS::$supported) {
			call_user_func($cb, $this, FS::statPrepare(fstat($this->fd)));
			return;
		}
		eio_fstat($this->fd, $pri, function ($file, $stat) use ($cb) {
			$stat = FS::statPrepare($stat);
			$file->stat = $stat;
			call_user_func($cb, $file, $stat);
		}, $this);
	}
	
	public function statvfs($cb, $pri = EIO_PRI_DEFAULT) {
		if (!$this->fd) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FS::$supported) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return;
		}
		if ($this->statvfs) {
			call_user_func($cb, $this, $this->statvfs);
		} else {
			eio_fstatvfs($this->fd, $pri, function ($file, $stat) use ($cb) {
				$file->statvfs = $stat;
				call_user_func($cb, $file, $stat);
			}, $this);		
		}
	}

	public function sync($cb, $pri = EIO_PRI_DEFAULT) {
		if (!$this->fd) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FS::$supported) {
			call_user_func($cb, $this, true);
			return false;
		}
		return eio_fsync($this->fd, $pri, $cb, $this);
	}
	
	public function datasync($cb, $pri = EIO_PRI_DEFAULT) {
		if (!$this->fd) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FS::$supported) {
			call_user_func($cb, $this, true);
			return false;
		}
		return eio_fdatasync($this->fd, $pri, $cb, $this);
	}
	
	public function write($data, $cb = null, $offset = null, $pri = EIO_PRI_DEFAULT) {
		if (!$this->fd) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FS::$supported) {
			if ($offset !== null) {
				fseek($data, $offset);
			}
			$r = fwrite($this->fd, $data);
			if ($cb) {
				call_user_func($cb, $this, $r);
			}
			return false;
		}
		if ($cb !== null) {
			$this->onWriteOnce->push($cb);
		}
		$l = strlen($data);
		if ($offset === null) {
			$offset = $this->offset;
			$this->offset += $l;
		}
		$this->writing = true;
		$res = eio_write($this->fd, $data, $l, $offset, $pri, function ($file, $result) {
			$this->writing = false;
			$this->onWriteOnce->executeAll($file, $result);
		}, $this);
		return $res;
	}
	
	public function chown($uid, $gid = -1, $cb, $pri = EIO_PRI_DEFAULT) {
		if (!$this->fd) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FS::$supported) {
			$r = chown($this->path, $uid);
			if ($gid !== -1) {
				$r = $r && chgrp($this->path, $gid);
			}
			if ($cb) {
				call_user_func($cb, $this, $r);
			}
			return false;
		}
		return eio_fchown($this->fd, $uid, $gid, $pri, $cb, $this);
	}
	
	public function touch($mtime, $atime = null, $cb = null, $pri = EIO_PRI_DEFAULT) {
		if (!$this->fd) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FS::$supported) {
			$r = touch($this->path, $mtime, $atime);
			if ($cb) {
				call_user_func($cb, $this, $r);
			}
			return false;
		}
		eio_futime($this->fd, $atime, $mtime, $pri, $cb, $this);
	}
	

	public function clearStatCache() {
		$this->stat = null;
		$this->statvfs = null;
	}
	
	public function read($length, $offset = null, $cb = null, $pri = EIO_PRI_DEFAULT) {
		if (!$this->fd) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FS::$supported) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		$this->offset += $length;
		$file = $this;
		eio_read(
			$this->fd,
			$length,
			$offset !== null ? $offset : $this->offset,
			$pri,
			$cb ? $cb: $this->onRead,
			$this
		);
		return true;
	}

	public function sendfile($outfd, $cb, $startCb = null, $offset = 0, $length = null, $pri = EIO_PRI_DEFAULT) {
		if (!$this->fd) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FS::$supported) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		static $chunkSize = 1024;
		$ret = true;
		$handler = function ($file, $sent = -1) use (&$ret, $outfd, $cb, &$handler, &$offset, &$length, $pri, $chunkSize) {
			if ($outfd instanceof IOStream) {
				if ($outfd->freed) {
					call_user_func($cb, $file, false);
					return;
				}
				$ofd = $outfd->fd;
			} else {
				$ofd = $outfd;
			}
			if (!$ret) {
				call_user_func($cb, $file, false);
				return;
			}
			if ($sent === -1) {
				$sent = 0;
			}
			$offset += $sent;
			$length -= $sent;
			if ($length <= 0) {
				call_user_func($cb, $file, true);
				return;
			}
			if (!$ofd) {
				call_user_func($cb, $file, false);
				return;
			}
			$c = min($chunkSize, $length);
			$ret = eio_sendfile($ofd, $file->fd, $offset, $c, $pri, $handler, $file);
		};
		if ($length !== null) {
			if ($startCb !== null) {
				if (!call_user_func($startCb, $file, $length, $handler)) {
					$handler($this);
				}
			} else {
				$handler($this);
			}
			return;
		}
		$this->statRefresh(function ($file, $stat) use ($startCb, $handler, &$length) {
			$length = $stat['size'];
			if ($startCb !== null) {
				if (!call_user_func($startCb, $file, $length, $handler)) {
					$handler($file);
				}
			} else {
				$handler($file);
			}
		}, $pri);
	}

	public function readahead($length, $offset = null, $cb = null, $pri = EIO_PRI_DEFAULT) {
		if (!$this->fd) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FS::$supported) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return;
		}
		$this->offset += $length;
		eio_readahead(
			$this->fd,
			$length,
			$offset !== null ? $offset : $this->pos,
			$pri,
			$cb,
			$this
		);
		return true;
	}

	public function readAll($cb, $pri = EIO_PRI_DEFAULT) {
		if (!$this->fd) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		$this->statRefresh(function ($file, $stat) use ($cb, $pri) {
			if (!$stat) {
				if ($cb) {
					call_user_func($cb, $file, false);
				}
				return;
			}
			$offset = 0;
			$buf = '';
			$size = $stat['size'];
			$handler = function ($file, $data) use ($cb, &$handler, $size, &$offset, $pri, &$buf) {
				$buf .= $data;
				$offset += strlen($data);
				$len = min($file->chunkSize, $size - $offset);
				if ($offset >= $size) {
					if ($cb) {
						call_user_func($cb, $file, $buf);
					}
					return;
				}
				eio_read($file->fd, $len, $offset, $pri, $handler, $this);
			};
			eio_read($file->fd, min($file->chunkSize, $size), 0, $pri, $handler, $file);
		}, $pri);
	}
	
	public function readAllChunked($cb = null, $chunkcb = null, $pri = EIO_PRI_DEFAULT) {
		if (!$this->fd) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		$this->statRefresh(function ($file, $stat) use ($cb, $chunkcb, $pri) {
			if (!$stat) {
				call_user_func($cb, $file, false);
				return;
			}
			$offset = 0;
			$size = $stat['size'];
			$handler = function ($file, $data) use ($cb, $chunkcb, &$handler, $size, &$offset, $pri) {
				call_user_func($chunkcb, $file, $data);
				$offset += strlen($data);
				$len = min($file->chunkSize, $size - $offset);
				if ($offset >= $size) {
					call_user_func($cb, $file, true);
					return;
				}
				eio_read($file->fd, $len, $offset, $pri, $handler, $file);
			};
			eio_read($file->fd, min($file->chunkSize, $size), $offset, $pri, $handler, $file);
		}, $pri);
	}
	public function __toString() {
		return $this->path;
	}
	public function setChunkSize($n) {
		$this->chunkSize = $n;
	}	
	public function seek($offset, $cb, $pri) {
		if (!EIO::$supported) {
			fseek($this->fd, $offset);
			return;
		}
		return eio_seek($this->fd, $offset, $pri, $cb, $this);
	}
	public function tell() {
		if (EIO::$supported) {
			return $this->pos;
		}
		return ftell($this->fd);
	}
	
	public function close() {
		if ($this->closed) {
			return;
		}
		$this->closed = true;
		if ($this->fdCacheKey !== null) {
			FS::$fdCache->invalidate($this->fdCacheKey);
		}
		if ($this->fd === null) {
			return;
		}

		if (!FS::$supported) {
			fclose($this->fd);
			return;
		}
		$r = eio_close($this->fd, EIO_PRI_MAX);
		$this->fd = null;
		return $r;
	}

	public function __destruct() {
		$this->close();
	}
}
