<?php

declare(strict_types=1);

namespace DoryoApi\Endpoint;

/**
 * Endpoint, který obsluhuje i jiné metody než GET.
 *
 * Bez tohohle rozhraní je endpoint jen ke čtení — tak vzniklo celé API a tak to má zůstat
 * výchozí chování. Zápis je vědomé rozhodnutí toho, kdo endpoint píše, ne něco, co se zapne
 * omylem tím, že handler dostane jiné tělo.
 */
interface MethodAware extends Endpoint
{
	/**
	 * Vzor cesty (stejný, jaký vrací {@see Endpoint::getRoutes()}) => povolené metody.
	 * Co se tu neuvede, zůstává na GET/HEAD.
	 * @return array<string, array<string>>
	 */
	public function getMethods(): array;
}
