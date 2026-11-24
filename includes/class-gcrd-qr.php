<?php
/**
 * Minimal QR Code encoder (byte mode, error correction level M, versions 1-10).
 *
 * Self-contained so the plugin has no external dependency or remote QR service.
 * Algorithm follows ISO/IEC 18004 (finder/timing/alignment patterns, Reed-Solomon
 * over GF(256) with polynomial 0x11D, BCH-coded format and version information).
 *
 * @package GiftCardRedeemDesk
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'GCRD_QR_STANDALONE' ) ) {
	exit;
}

class GCRD_QR {

	/** RS block structure for level M: [count, total, data, (count, total, data)] per version. */
	const BLOCKS_M = array(
		1  => array( 1, 26, 16 ),
		2  => array( 1, 44, 28 ),
		3  => array( 1, 70, 44 ),
		4  => array( 2, 50, 32 ),
		5  => array( 2, 67, 43 ),
		6  => array( 4, 43, 27 ),
		7  => array( 4, 49, 31 ),
		8  => array( 2, 60, 38, 2, 61, 39 ),
		9  => array( 3, 58, 36, 2, 59, 37 ),
		10 => array( 4, 69, 43, 1, 70, 44 ),
	);

	const ALIGN = array(
		1  => array(),
		2  => array( 6, 18 ),
		3  => array( 6, 22 ),
		4  => array( 6, 26 ),
		5  => array( 6, 30 ),
		6  => array( 6, 34 ),
		7  => array( 6, 22, 38 ),
		8  => array( 6, 24, 42 ),
		9  => array( 6, 26, 46 ),
		10 => array( 6, 28, 50 ),
	);

	/** @var int */
	private $size;
	/** @var array<int,array<int,bool>> */
	private $modules;
	/** @var array<int,array<int,bool>> */
	private $is_function;

	/**
	 * Encode text into a module matrix.
	 *
	 * @param string $text UTF-8 text (max ~213 bytes).
	 * @return array<int,array<int,bool>> rows of booleans (true = dark).
	 * @throws InvalidArgumentException When the text is too long.
	 */
	public static function matrix( $text ) {
		$qr = new self();
		return $qr->encode( (string) $text );
	}

	/**
	 * Render as PNG binary (GD). Returns null when GD is unavailable.
	 *
	 * @param string $text   Payload.
	 * @param int    $scale  Pixels per module.
	 * @param int    $margin Quiet zone in modules.
	 * @param string $dark   Hex colour of dark modules.
	 * @param string $light  Hex colour of light modules.
	 * @return string|null
	 */
	public static function png( $text, $scale = 6, $margin = 4, $dark = '#000000', $light = '#ffffff' ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return null;
		}
		$m    = self::matrix( $text );
		$n    = count( $m );
		$px   = ( $n + 2 * $margin ) * $scale;
		$img  = imagecreatetruecolor( $px, $px );
		$rgbL = self::hex2rgb( $light );
		$rgbD = self::hex2rgb( $dark );
		$cL   = imagecolorallocate( $img, $rgbL[0], $rgbL[1], $rgbL[2] );
		$cD   = imagecolorallocate( $img, $rgbD[0], $rgbD[1], $rgbD[2] );
		imagefilledrectangle( $img, 0, 0, $px - 1, $px - 1, $cL );
		for ( $y = 0; $y < $n; $y++ ) {
			for ( $x = 0; $x < $n; $x++ ) {
				if ( $m[ $y ][ $x ] ) {
					$x0 = ( $x + $margin ) * $scale;
					$y0 = ( $y + $margin ) * $scale;
					imagefilledrectangle( $img, $x0, $y0, $x0 + $scale - 1, $y0 + $scale - 1, $cD );
				}
			}
		}
		ob_start();
		imagepng( $img );
		$data = ob_get_clean();
		imagedestroy( $img );
		return $data;
	}

	/**
	 * Render as SVG markup.
	 *
	 * @param string $text   Payload.
	 * @param int    $px     Output width/height in px.
	 * @param int    $margin Quiet zone in modules.
	 * @param string $dark   Dark colour.
	 * @param string $light  Light colour.
	 * @return string
	 */
	public static function svg( $text, $px = 200, $margin = 4, $dark = '#000000', $light = '#ffffff' ) {
		$m    = self::matrix( $text );
		$n    = count( $m );
		$dim  = $n + 2 * $margin;
		$path = '';
		for ( $y = 0; $y < $n; $y++ ) {
			for ( $x = 0; $x < $n; $x++ ) {
				if ( $m[ $y ][ $x ] ) {
					$path .= 'M' . ( $x + $margin ) . ' ' . ( $y + $margin ) . 'h1v1h-1z';
				}
			}
		}
		return '<svg xmlns="http://www.w3.org/2000/svg" width="' . (int) $px . '" height="' . (int) $px . '" viewBox="0 0 ' . $dim . ' ' . $dim . '" shape-rendering="crispEdges">'
			. '<rect width="100%" height="100%" fill="' . $light . '"/>'
			. '<path d="' . $path . '" fill="' . $dark . '"/></svg>';
	}

	/* ------------------------------------------------------------------ */

	private static function hex2rgb( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
			$hex = '000000';
		}
		return array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
	}

	private function encode( $text ) {
		$bytes   = array_values( unpack( 'C*', $text ) ?: array() );
		$len     = count( $bytes );
		$version = 0;
		for ( $v = 1; $v <= 10; $v++ ) {
			$cap_bits = $this->data_codewords( $v ) * 8;
			$need     = 4 + ( $v < 10 ? 8 : 16 ) + 8 * $len;
			if ( $need <= $cap_bits ) {
				$version = $v;
				break;
			}
		}
		if ( ! $version ) {
			throw new InvalidArgumentException( 'QR payload too long.' );
		}

		// Bit stream.
		$bits = array();
		$this->append_bits( $bits, 0x4, 4 );
		$this->append_bits( $bits, $len, $version < 10 ? 8 : 16 );
		foreach ( $bytes as $b ) {
			$this->append_bits( $bits, $b, 8 );
		}
		$cap_bits = $this->data_codewords( $version ) * 8;
		$this->append_bits( $bits, 0, min( 4, $cap_bits - count( $bits ) ) );
		$this->append_bits( $bits, 0, ( 8 - count( $bits ) % 8 ) % 8 );
		for ( $pad = 0xEC; count( $bits ) < $cap_bits; $pad ^= 0xEC ^ 0x11 ) {
			$this->append_bits( $bits, $pad, 8 );
		}
		$data = array();
		for ( $i = 0; $i < count( $bits ); $i += 8 ) {
			$byte = 0;
			for ( $j = 0; $j < 8; $j++ ) {
				$byte = ( $byte << 1 ) | $bits[ $i + $j ];
			}
			$data[] = $byte;
		}

		$codewords = $this->add_ecc_and_interleave( $data, $version );

		// Matrix.
		$this->size        = $version * 4 + 17;
		$this->modules     = array_fill( 0, $this->size, array_fill( 0, $this->size, false ) );
		$this->is_function = array_fill( 0, $this->size, array_fill( 0, $this->size, false ) );
		$this->draw_function_patterns( $version );
		$this->draw_codewords( $codewords );

		$best_mask    = 0;
		$best_penalty = PHP_INT_MAX;
		for ( $mask = 0; $mask < 8; $mask++ ) {
			$this->apply_mask( $mask );
			$this->draw_format_bits( $mask );
			$p = $this->penalty();
			if ( $p < $best_penalty ) {
				$best_penalty = $p;
				$best_mask    = $mask;
			}
			$this->apply_mask( $mask ); // Undo (XOR).
		}
		$this->apply_mask( $best_mask );
		$this->draw_format_bits( $best_mask );
		return $this->modules;
	}

	private function data_codewords( $version ) {
		$b   = self::BLOCKS_M[ $version ];
		$sum = 0;
		for ( $i = 0; $i < count( $b ); $i += 3 ) {
			$sum += $b[ $i ] * $b[ $i + 2 ];
		}
		return $sum;
	}

	private function append_bits( array &$bits, $val, $len ) {
		for ( $i = $len - 1; $i >= 0; $i-- ) {
			$bits[] = ( $val >> $i ) & 1;
		}
	}

	private function add_ecc_and_interleave( array $data, $version ) {
		$spec   = self::BLOCKS_M[ $version ];
		$blocks = array();
		$k      = 0;
		for ( $i = 0; $i < count( $spec ); $i += 3 ) {
			for ( $c = 0; $c < $spec[ $i ]; $c++ ) {
				$dc       = $spec[ $i + 2 ];
				$ecn      = $spec[ $i + 1 ] - $dc;
				$dat      = array_slice( $data, $k, $dc );
				$k       += $dc;
				$blocks[] = array( $dat, $this->rs_remainder( $dat, $this->rs_divisor( $ecn ) ) );
			}
		}
		$out     = array();
		$max_dat = 0;
		foreach ( $blocks as $b ) {
			$max_dat = max( $max_dat, count( $b[0] ) );
		}
		for ( $i = 0; $i < $max_dat; $i++ ) {
			foreach ( $blocks as $b ) {
				if ( $i < count( $b[0] ) ) {
					$out[] = $b[0][ $i ];
				}
			}
		}
		$ecn = count( $blocks[0][1] );
		for ( $i = 0; $i < $ecn; $i++ ) {
			foreach ( $blocks as $b ) {
				$out[] = $b[1][ $i ];
			}
		}
		return $out;
	}

	private function gf_mul( $x, $y ) {
		$z = 0;
		for ( $i = 7; $i >= 0; $i-- ) {
			$z  = ( ( $z << 1 ) ^ ( ( $z >> 7 ) * 0x11D ) ) & 0xFF;
			$z ^= ( ( $y >> $i ) & 1 ) * $x;
		}
		return $z;
	}

	private function rs_divisor( $degree ) {
		$result                = array_fill( 0, $degree, 0 );
		$result[ $degree - 1 ] = 1;
		$root                  = 1;
		for ( $i = 0; $i < $degree; $i++ ) {
			for ( $j = 0; $j < $degree; $j++ ) {
				$result[ $j ] = $this->gf_mul( $result[ $j ], $root );
				if ( $j + 1 < $degree ) {
					$result[ $j ] ^= $result[ $j + 1 ];
				}
			}
			$root = $this->gf_mul( $root, 0x02 );
		}
		return $result;
	}

	private function rs_remainder( array $data, array $divisor ) {
		$result = array_fill( 0, count( $divisor ), 0 );
		foreach ( $data as $b ) {
			$factor   = $b ^ array_shift( $result );
			$result[] = 0;
			foreach ( $divisor as $i => $coef ) {
				$result[ $i ] ^= $this->gf_mul( $coef, $factor );
			}
		}
		return $result;
	}

	private function set_function( $x, $y, $dark ) {
		$this->modules[ $y ][ $x ]     = (bool) $dark;
		$this->is_function[ $y ][ $x ] = true;
	}

	private function draw_function_patterns( $version ) {
		$n = $this->size;
		for ( $i = 0; $i < $n; $i++ ) {
			$this->set_function( 6, $i, 0 === $i % 2 );
			$this->set_function( $i, 6, 0 === $i % 2 );
		}
		$this->draw_finder( 3, 3 );
		$this->draw_finder( $n - 4, 3 );
		$this->draw_finder( 3, $n - 4 );

		$pos = self::ALIGN[ $version ];
		$cnt = count( $pos );
		for ( $i = 0; $i < $cnt; $i++ ) {
			for ( $j = 0; $j < $cnt; $j++ ) {
				if ( ( 0 === $i && 0 === $j ) || ( 0 === $i && $cnt - 1 === $j ) || ( $cnt - 1 === $i && 0 === $j ) ) {
					continue;
				}
				for ( $dy = -2; $dy <= 2; $dy++ ) {
					for ( $dx = -2; $dx <= 2; $dx++ ) {
						$this->set_function( $pos[ $i ] + $dx, $pos[ $j ] + $dy, 1 !== max( abs( $dx ), abs( $dy ) ) );
					}
				}
			}
		}
		$this->draw_format_bits( 0 ); // Reserve.
		if ( $version >= 7 ) {
			$rem = $version;
			for ( $i = 0; $i < 12; $i++ ) {
				$rem = ( $rem << 1 ) ^ ( ( $rem >> 11 ) * 0x1F25 );
			}
			$bits = ( $version << 12 ) | $rem;
			for ( $i = 0; $i < 18; $i++ ) {
				$bit = ( $bits >> $i ) & 1;
				$a   = $n - 11 + $i % 3;
				$b   = intdiv( $i, 3 );
				$this->set_function( $a, $b, $bit );
				$this->set_function( $b, $a, $bit );
			}
		}
	}

	private function draw_finder( $cx, $cy ) {
		for ( $dy = -4; $dy <= 4; $dy++ ) {
			for ( $dx = -4; $dx <= 4; $dx++ ) {
				$d = max( abs( $dx ), abs( $dy ) );
				$x = $cx + $dx;
				$y = $cy + $dy;
				if ( $x >= 0 && $x < $this->size && $y >= 0 && $y < $this->size ) {
					$this->set_function( $x, $y, 2 !== $d && 4 !== $d );
				}
			}
		}
	}

	private function draw_format_bits( $mask ) {
		$data = ( 0 << 3 ) | $mask; // Level M = 0b00.
		$rem  = $data;
		for ( $i = 0; $i < 10; $i++ ) {
			$rem = ( $rem << 1 ) ^ ( ( $rem >> 9 ) * 0x537 );
		}
		$bits = ( ( $data << 10 ) | $rem ) ^ 0x5412;
		$n    = $this->size;
		$bit  = function ( $i ) use ( $bits ) {
			return ( $bits >> $i ) & 1;
		};
		for ( $i = 0; $i <= 5; $i++ ) {
			$this->set_function( 8, $i, $bit( $i ) );
		}
		$this->set_function( 8, 7, $bit( 6 ) );
		$this->set_function( 8, 8, $bit( 7 ) );
		$this->set_function( 7, 8, $bit( 8 ) );
		for ( $i = 9; $i < 15; $i++ ) {
			$this->set_function( 14 - $i, 8, $bit( $i ) );
		}
		for ( $i = 0; $i < 8; $i++ ) {
			$this->set_function( $n - 1 - $i, 8, $bit( $i ) );
		}
		for ( $i = 8; $i < 15; $i++ ) {
			$this->set_function( 8, $n - 15 + $i, $bit( $i ) );
		}
		$this->set_function( 8, $n - 8, 1 );
	}

	private function draw_codewords( array $data ) {
		$n     = $this->size;
		$i     = 0;
		$total = count( $data ) * 8;
		for ( $right = $n - 1; $right >= 1; $right -= 2 ) {
			if ( 6 === $right ) {
				$right = 5;
			}
			for ( $vert = 0; $vert < $n; $vert++ ) {
				for ( $j = 0; $j < 2; $j++ ) {
					$x      = $right - $j;
					$upward = 0 === ( ( $right + 1 ) & 2 );
					$y      = $upward ? $n - 1 - $vert : $vert;
					if ( ! $this->is_function[ $y ][ $x ] && $i < $total ) {
						$this->modules[ $y ][ $x ] = (bool) ( ( $data[ $i >> 3 ] >> ( 7 - ( $i & 7 ) ) ) & 1 );
						$i++;
					}
				}
			}
		}
	}

	private function apply_mask( $mask ) {
		$n = $this->size;
		for ( $y = 0; $y < $n; $y++ ) {
			for ( $x = 0; $x < $n; $x++ ) {
				if ( $this->is_function[ $y ][ $x ] ) {
					continue;
				}
				switch ( $mask ) {
					case 0:
						$inv = 0 === ( $x + $y ) % 2;
						break;
					case 1:
						$inv = 0 === $y % 2;
						break;
					case 2:
						$inv = 0 === $x % 3;
						break;
					case 3:
						$inv = 0 === ( $x + $y ) % 3;
						break;
					case 4:
						$inv = 0 === ( intdiv( $x, 3 ) + intdiv( $y, 2 ) ) % 2;
						break;
					case 5:
						$inv = 0 === ( $x * $y % 2 + $x * $y % 3 );
						break;
					case 6:
						$inv = 0 === ( $x * $y % 2 + $x * $y % 3 ) % 2;
						break;
					default:
						$inv = 0 === ( ( $x + $y ) % 2 + $x * $y % 3 ) % 2;
				}
				if ( $inv ) {
					$this->modules[ $y ][ $x ] = ! $this->modules[ $y ][ $x ];
				}
			}
		}
	}

	private function penalty() {
		$n     = $this->size;
		$m     = $this->modules;
		$score = 0;
		// N1: runs of 5+ same colour in rows/cols.
		for ( $pass = 0; $pass < 2; $pass++ ) {
			for ( $a = 0; $a < $n; $a++ ) {
				$run  = 1;
				$prev = $pass ? $m[0][ $a ] : $m[ $a ][0];
				for ( $b = 1; $b < $n; $b++ ) {
					$cur = $pass ? $m[ $b ][ $a ] : $m[ $a ][ $b ];
					if ( $cur === $prev ) {
						$run++;
					} else {
						if ( $run >= 5 ) {
							$score += $run - 2;
						}
						$run  = 1;
						$prev = $cur;
					}
				}
				if ( $run >= 5 ) {
					$score += $run - 2;
				}
			}
		}
		// N2: 2x2 blocks.
		for ( $y = 0; $y < $n - 1; $y++ ) {
			for ( $x = 0; $x < $n - 1; $x++ ) {
				$c = $m[ $y ][ $x ];
				if ( $c === $m[ $y ][ $x + 1 ] && $c === $m[ $y + 1 ][ $x ] && $c === $m[ $y + 1 ][ $x + 1 ] ) {
					$score += 3;
				}
			}
		}
		// N3: finder-like patterns.
		$pat1 = '10111010000';
		$pat2 = '00001011101';
		for ( $a = 0; $a < $n; $a++ ) {
			$row = '';
			$col = '';
			for ( $b = 0; $b < $n; $b++ ) {
				$row .= $m[ $a ][ $b ] ? '1' : '0';
				$col .= $m[ $b ][ $a ] ? '1' : '0';
			}
			foreach ( array( $row, $col ) as $line ) {
				$score += 40 * ( substr_count( $line, $pat1 ) + substr_count( $line, $pat2 ) );
			}
		}
		// N4: dark/light balance.
		$dark = 0;
		foreach ( $m as $r ) {
			foreach ( $r as $c ) {
				$dark += $c ? 1 : 0;
			}
		}
		$total  = $n * $n;
		$k      = (int) ( abs( $dark * 20 - $total * 10 ) / $total );
		$score += $k * 10;
		return $score;
	}
}
