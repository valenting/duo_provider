<?php

/**
 * @author El-ad Blech <elie@theinfamousblix.com>
 *
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\Duo\Provider;
global $conf_ini_array;

use OCP\Authentication\TwoFactorAuth\IProvider2;
use OCP\IUser;
use OCP\Template;

require_once 'duo/lib/Web.php';

$conf_ini_array = parse_ini_file('duo/duo.ini',1);

/**
 * Check if a given ip is in a network
 * @param  string $ip    IP to check in IPV4 format eg. 127.0.0.1
 * @param  string $range IP/CIDR netmask eg. 127.0.0.0/24, also 127.0.0.1 is accepted and /32 assumed
 * @return boolean true if the ip is in this range / false if not.
 */
function ip_in_range( $ip, $range ) {
	if ( strpos( $range, '/' ) == false ) {
		$range .= '/32';
	}
	// $range is in IP/CIDR format eg 127.0.0.1/24
	list( $range, $netmask ) = explode( '/', $range, 2 );
	$range_decimal = ip2long( $range );
	$ip_decimal = ip2long( $ip );
	$wildcard_decimal = pow( 2, ( 32 - $netmask ) ) - 1;
	$netmask_decimal = ~ $wildcard_decimal;
	return ( ( $ip_decimal & $netmask_decimal ) == ( $range_decimal & $netmask_decimal ) );
}

class DuoProvider implements IProvider2 {

	/**
	 * Get unique identifier of this 2FA provider
	 *
	 * @return string
	 */
	public function getId() {
		return 'duo';
	}

	/**
	 * Get the display name for selecting the 2FA provider
	 *
	 * @return string
	 */
	public function getDisplayName() {
		return 'Duo';
	}

	/**
	 * Get the description for selecting the 2FA provider
	 *
	 * @return string
	 */
	public function getDescription() {
		return 'Duo';
	}

	/**
         * Get the Content Security Policy for the template (required for showing external content, otherwise optional)
         *
         * @return \OCP\AppFramework\Http\ContentSecurityPolicy
         */

	public function getCSP() {
		$csp = new \OCP\AppFramework\Http\ContentSecurityPolicy();
		$csp->addAllowedChildSrcDomain('https://*.duosecurity.com');
		$csp->addAllowedStyleDomain('https://*.duosecurity.com');
		$csp->addAllowedFrameDomain('https://*.duosecurity.com');
                return $csp;
        }

	/**
	 * Get the template for rending the 2FA provider view
	 *
	 * @param IUser $user
	 * @return Template
	 */
	public function getTemplate(IUser $user) {
		global $conf_ini_array;
		$tmpl = new Template('duo', 'challenge');
		$tmpl->assign('user', $user->getUID());
		$tmpl->assign('IKEY', $conf_ini_array['duo_app_settings']['IKEY']);
		$tmpl->assign('SKEY', $conf_ini_array['duo_app_settings']['SKEY']);
		$tmpl->assign('AKEY', $conf_ini_array['duo_app_settings']['AKEY']);
		$tmpl->assign('HOST', $conf_ini_array['duo_app_settings']['HOST']);
		return $tmpl;
	}

	/**
	 * Verify the given challenge
	 *
	 * @param IUser $user
	 * @param string $challenge
	 */
	public function verifyChallenge(IUser $user, $challenge) {
		global $conf_ini_array;	
	
		$IKEY = $conf_ini_array['duo_app_settings']['IKEY'];
		$SKEY = $conf_ini_array['duo_app_settings']['SKEY'];
		$AKEY = $conf_ini_array['duo_app_settings']['AKEY'];

		$resp = \Duo\Web::verifyResponse($IKEY, $SKEY, $AKEY, $challenge);
		if ($resp) {
			return true;
		}
		return false;
	}

	/**
	 * Decides whether 2FA is enabled for the given user
	 *
	 * @param IUser $user
	 * @return boolean
	 */
	public function isTwoFactorAuthEnabledForUser(IUser $user) {
		global $conf_ini_array;

		// If configured in duo.ini, LDAP users will bypass Duo 2FA
		if (isset($conf_ini_array['custom_settings']['LDAP_BYPASS']) && $conf_ini_array['custom_settings']['LDAP_BYPASS'] === true) {
			// Check the backend of the user and bypass Duo if LDAP
			$backend = $user->getBackendClassName();
			if ($backend == 'LDAP')
				return false;
			else
				return true;
		}
		// If configured in duo.ini, source IP addresses specified in the IP_BYPASS array will bypass Duo 2FA
		if (isset($conf_ini_array['custom_settings']['IP_BYPASS'])) {
			$IP_BYPASS = $conf_ini_array['custom_settings']['IP_BYPASS'];
			$remote_ip = (string)trim((getenv(REMOTE_ADDR)));

			$count = count($IP_BYPASS);
			for ($i = 0; $i < $count; $i++) {
				if (ip_in_range($remote_ip, $IP_BYPASS[$i])) {
					return false;
				}
			}

		}
        return true; // Fallback to requiring 2FA
	}

}
