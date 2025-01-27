import ko from 'ko';

import { Capa } from 'Common/Enums';
import { Settings } from 'Common/Globals';
import { delegateRunOnDestroy } from 'Common/UtilsUser';

//import { EmailModel } from 'Model/Email';
//import { OpenPgpKeyModel } from 'Model/OpenPgpKey';

import Remote from 'Remote/User/Fetch';

import { showScreenPopup } from 'Knoin/Knoin';
import { OpenPgpKeyPopupView } from 'View/Popup/OpenPgpKey';

const
	findGnuPGKey = (keys, query, sign) =>
		keys.find(key =>
			key[sign ? 'can_sign' : 'can_decrypt']
			&& (key.emails.includes(query) || key.subkeys.find(key => query == key.keyid || query == key.fingerprint))
		);

export const GnuPGUserStore = new class {
	constructor() {
		/**
		 * PECL gnupg / PEAR Crypt_GPG
		 * [ {email, can_encrypt, can_sign}, ... ]
		 */
		this.keyring;
		this.publicKeys = ko.observableArray();
		this.privateKeys = ko.observableArray();
	}

	loadKeyrings(/*identifier*/) {
		this.keyring = null;
		this.publicKeys([]);
		this.privateKeys([]);
		Remote.request('GnupgGetKeys',
			(iError, oData) => {
				if (oData && oData.Result) {
					this.keyring = oData.Result;
					const initKey = (key, isPrivate) => {
						const aEmails = [];
						key.id = key.subkeys[0].keyid;
						key.fingerprint = key.subkeys[0].fingerprint;
						key.uids.forEach(uid => uid.email && aEmails.push(uid.email));
						key.emails = aEmails;
						key.askDelete = ko.observable(false);
						key.openForDeletion = ko.observable(null).askDeleteHelper();
						key.remove = () => {
							if (key.askDelete()) {
								Remote.request('GnupgDeleteKey',
									(iError, oData) => {
										if (oData && oData.Result) {
											if (isPrivate) {
												this.privateKeys.remove(key);
											} else {
												this.publicKeys.remove(key);
											}
											delegateRunOnDestroy(key);
										}
									}, {
										KeyId: key.id,
										isPrivate: isPrivate
									}
								);
							}
						};
						key.view = () => {
							let pass = isPrivate ? prompt('Passphrase') : true;
							if (pass) {
								Remote.request('GnupgExportKey',
									(iError, oData) => {
										if (oData && oData.Result) {
											key.armor = oData.Result;
											showScreenPopup(OpenPgpKeyPopupView, [key]);
										}
									}, {
										KeyId: key.id,
										isPrivate: isPrivate,
										Passphrase: isPrivate ? pass : ''
									}
								);
							}
						};
						return key;
					};
					this.publicKeys(oData.Result.public.map(key => initKey(key, 0)));
					this.privateKeys(oData.Result.private.map(key => initKey(key, 1)));
					console.log('gnupg ready');
				}
			}
		);
	}

	/**
	 * @returns {boolean}
	 */
	isSupported() {
		return Settings.capa(Capa.GnuPG);
	}

	importKey(key, callback) {
		Remote.request('GnupgImportKey',
			(iError, oData) => {
				if (oData && oData.Result) {
//					this.gnupgKeyring = oData.Result;
				}
				callback && callback(iError, oData);
			}, {
				Key: key
			}
		);
	}

	/**
		keyPair.privateKey
		keyPair.publicKey
		keyPair.revocationCertificate
		keyPair.onServer
		keyPair.inGnuPG
	 */
	storeKeyPair(keyPair, callback) {
		Remote.request('PgpStoreKeyPair',
			(iError, oData) => {
				if (oData && oData.Result) {
//					this.gnupgKeyring = oData.Result;
				}
				callback && callback(iError, oData);
			}, keyPair
		);
	}

	/**
	 * Checks if verifying/encrypting a message is possible with given email addresses.
	 */
	hasPublicKeyForEmails(recipients) {
		const count = recipients.length,
			length = count ? recipients.filter(email =>
//				(key.can_verify || key.can_encrypt) &&
				this.publicKeys.find(key => key.emails.includes(email))
			).length : 0;
		return length && length === count;
	}

	getPrivateKeyFor(query, sign) {
		return findGnuPGKey(this.privateKeys, query, sign);
	}

	getPublicKeyFor(query, sign) {
		return findGnuPGKey(this.publicKeys, query, sign);
	}

	async decrypt(message) {
		const
			pgpInfo = message.pgpEncrypted();
		if (pgpInfo) {
			let ids = [message.to[0].email].concat(pgpInfo.KeyIds),
				i = ids.length, key;
			while (i--) {
				key = findGnuPGKey(this.privateKeys, ids[i]);
				if (key) {
					break;
				}
			}
			if (key) {
				// Also check message.from[0].email
				let params = {
					Folder: message.folder,
					Uid: message.uid,
					PartId: pgpInfo.PartId,
					KeyId: key.id,
					Passphrase: prompt('GnuPG Passphrase for ' + key.id + ' ' + key.uids[0].uid),
					Data: '' // message.plain() optional
				}
				if (null !== params.Passphrase) {
					const result = await Remote.post('GnupgDecrypt', null, params);
					if (result && result.Result) {
						return result.Result;
					}
				}
			}
		}
	}

	async verify(message) {
		let data = message.pgpSigned(); // { BodyPartId: "1", SigPartId: "2", MicAlg: "pgp-sha256" }
		if (data) {
//			const sender = message.from[0].email;
//			let mode = await this.hasPublicKeyForEmails([sender]);
			data.Folder = message.folder;
			data.Uid = message.uid;
			let response = await Remote.post('MessagePgpVerify', null, data);
			if (response && response.Result) {
				return {
					fingerprint: response.Result.fingerprint,
					success: 0 == response.Result.status // GOODSIG
				};
			}
		}
	}

};
