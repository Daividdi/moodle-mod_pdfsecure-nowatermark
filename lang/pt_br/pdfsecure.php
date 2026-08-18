<?php
$string['pluginname'] = 'PDF Secure';
$string['modulename'] = 'PDF Secure';
$string['modulename_help'] = 'O módulo PDF Secure entrega PDFs do curso por uma rota autenticada: o arquivo enviado não tem URL própria e o visualizador abre sem os botões de download e impressão. Por padrão ele também carimba cada página com o nome de quem abriu o documento. Um documento que pode ser lido sempre pode ser copiado — o que o carimbo garante é que qualquer cópia em circulação identifica a conta de onde saiu. Sites cujas estações já aplicam a própria marca d\'água podem desligar o carimbo nas configurações do plugin e manter todo o resto.';
$string['modulenameplural'] = 'PDFs Secures';
$string['pluginadministration'] = 'Administração do PDF Secure';
$string['pdfsecurename'] = 'Nome do PDF';
$string['pdfsecurename_help'] = 'Este é o nome do link que os estudantes verão na página do curso.';

// Se deve carimbar.
$string['settingstampmode'] = 'Aplicar marca d\'água nos documentos';
$string['settingstampmode_desc'] = 'Se este site grava uma marca d\'água por leitor dentro de cada PDF que entrega. Desligue apenas onde as estações de trabalho já aplicam a própria marca — uma segunda marca sobre a primeira não acrescenta rastreabilidade e deixa o documento mais difícil de ler. Desligar não muda mais nada: o arquivo enviado continua sem URL própria, o leitor continua precisando estar autenticado e matriculado, o visualizador continua escondendo download e impressão, e o texto do documento continua indexado na Pesquisa Global. Também tira a etapa de reescrita do PDF, então documentos cifrados e arquivos com links, marcadores ou campos de formulário são entregues intactos em vez de recusados ou achatados.';
$string['stampmodefull'] = 'Sim — carimbar cada página com a identidade de quem lê';
$string['stampmodeoff'] = 'Não — entregar o documento sem marca (para sites com marca d\'água na estação)';
