<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Strings em português do Brasil do complemento do MiwLab.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['error:categorynotfound'] = 'A categoria não existe.';
$string['error:coursedeleted'] = 'O curso foi restaurado, mas foi excluído depois.';
$string['error:coursenotfound'] = 'O curso não existe.';
$string['error:draftfileempty'] = 'O pacote na área de rascunho está vazio.';
$string['error:draftfilenotfound'] = 'O pacote não está na área de rascunho da conta. Envie-o de novo (webservice/upload.php) e use o itemid devolvido.';
$string['error:draftfiletoomany'] = 'A área de rascunho tem mais de um arquivo: informe o nome do pacote.';
$string['error:feedbacknotfound'] = 'A pesquisa não existe.';
$string['error:invalidmarker'] = 'A marcação precisa começar com "miwlab:" e ter só letras, números, "-", "_" e no máximo mais um ":".';
$string['error:missingcapabilities'] = 'A conta não pode restaurar na categoria "{$a->category}": falta ao papel dela ali {$a->capabilities}.';
$string['error:notabackup'] = 'O arquivo não é uma cópia de segurança de curso do Moodle (.mbz).';
$string['error:notanonymous'] = 'A pesquisa não é anônima: as respostas dela não são lidas.';
$string['error:precheck'] = 'O Moodle recusou o pacote antes de restaurar: {$a}';
$string['error:restorenotfound'] = 'Não há restauração com este identificador (o registro fica 30 dias depois do fim).';
$string['error:stalled'] = 'A restauração não terminou em 48 horas: a tarefa em segundo plano parou. Confira se o cron do Moodle está rodando e peça de novo.';
$string['miwlab:restorecourse'] = 'Restaurar um pacote do MiwLab como curso novo';
$string['miwlab:viewreports'] = 'Ler as contagens consolidadas de um curso para o MiwLab (conclusão e pesquisa anônima)';
$string['pluginname'] = 'MiwLab';
$string['privacy:metadata'] = 'O complemento do MiwLab não guarda dado pessoal: devolve só contagens e guarda o andamento de cada restauração sem a pessoa que a pediu.';
$string['task:cleanuprestores'] = 'Esquecer o andamento das restaurações antigas do MiwLab';
